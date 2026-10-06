<?php

use App\Actions\Customers\ImportCustomersFromCsv;
use App\Enums\GhanaRegion;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\OpeningBalanceFixture;

/*
 * docs/acceptance-phase3.md 6.2, calculated by hand before the code.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    OpeningBalanceFixture::existingAcademy();
    OpeningBalanceFixture::at('2026-09-01 08:00');
});

afterEach(fn () => Carbon::setTestNow());

function planRows(array $plan): array
{
    return array_map(fn ($r) => [$r['row'], $r['action'], $r['detail']], $plan['rows']);
}

test('dry run: what would be created, skipped and refused, and the opening balance total; nothing written', function () {
    $plan = app(ImportCustomersFromCsv::class)->plan(OpeningBalanceFixture::CSV);

    expect(planRows($plan))->toBe([
        [2, 'create', 'opening balance GHS 2,500.00, due 2026-09-30'],
        [3, 'create', 'opening balance GHS 1,200.00, due 2026-10-15'],
        [4, 'create', 'no opening balance'],
        [5, 'skip', 'already a customer (CUS-0001, same name and phone)'],
        [6, 'error', 'region is required'],
        [7, 'error', 'opening balance "12.345" is not an amount in GHS with at most 2 decimals'],
        [8, 'skip', 'same school as row 2 in this file'],
    ])
        ->and([$plan['create'], $plan['skip'], $plan['errors'], $plan['opening_balance_total']])->toBe([3, 2, 2, 370000])
        ->and(Customer::query()->count())->toBe(1)
        ->and(Sale::query()->count())->toBe(0);
});

test('a commit with errors is refused whole; the fixed file creates the customers and opening balances', function () {
    $import = app(ImportCustomersFromCsv::class);

    expect(fn () => $import->commit($this->owner, OpeningBalanceFixture::CSV))
        ->toThrow(InvalidArgumentException::class, 'Nothing imported: fix the 2 row(s) with errors first.');
    expect(Customer::query()->count())->toBe(1);

    $result = $import->commit($this->owner, OpeningBalanceFixture::fixedCsv());
    expect($result['created'])->toBe(['CUS-0002', 'CUS-0003', 'CUS-0004'])
        ->and($result['plan']['opening_balance_total'])->toBe(370000);

    $hilltop = Customer::query()->where('code', 'CUS-0002')->sole();
    expect($hilltop->only(['name', 'region', 'district', 'address', 'contact_person', 'phone', 'credit_limit', 'outstanding_balance']))->toBe([
        'name' => 'Kasoa Hilltop School', 'region' => GhanaRegion::Central, 'district' => 'Awutu Senya East Municipal', 'address' => 'Kasoa',
        'contact_person' => 'Mr Annan', 'phone' => '0244000001', 'credit_limit' => 500000, 'outstanding_balance' => 250000,
    ]);
    expect(Sale::query()->orderBy('id')->get()->map(fn (Sale $s) => [$s->invoice_no, $s->customer->code, $s->total, $s->sale_date->toDateString(), $s->due_date->toDateString(), $s->is_opening_balance, $s->items()->count()])->all())->toBe([
        ['OB-2026-000001', 'CUS-0002', 250000, '2026-08-31', '2026-09-30', true, 0],
        ['OB-2026-000002', 'CUS-0003', 120000, '2026-08-31', '2026-10-15', true, 0],
    ])
        ->and(Customer::query()->where('code', 'CUS-0004')->value('outstanding_balance'))->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
    assertMoneyInvariants();
});

test('running the same file again creates nothing: every row matches', function () {
    $import = app(ImportCustomersFromCsv::class);
    $import->commit($this->owner, OpeningBalanceFixture::fixedCsv());

    $again = $import->commit($this->owner, OpeningBalanceFixture::fixedCsv());

    expect($again['created'])->toBe([])
        ->and(array_column($again['plan']['rows'], 'action'))->toBe(['skip', 'skip', 'skip', 'skip', 'skip'])
        ->and($again['plan']['opening_balance_total'])->toBe(0)
        ->and(Customer::query()->count())->toBe(4)
        ->and(Sale::query()->count())->toBe(2);
});

test('matching by code, by name + phone (spaces, case, +233), and rows without a phone', function () {
    $csv = "code,name,region,phone\n"
        ."CUS-0001,Some Other Name,Central,\n"         // code wins
        .",EXISTING  academy,Central,+233 24 400 0004\n" // name and phone written differently
        .",Existing Academy,Central,0209999999\n";       // same name, other phone: a different school
    $plan = app(ImportCustomersFromCsv::class)->plan($csv);

    expect(planRows($plan))->toBe([
        [2, 'skip', 'already a customer (CUS-0001)'],
        [3, 'skip', 'already a customer (CUS-0001, same name and phone)'],
        [4, 'create', 'no opening balance'],
    ]);
});

test('bad files and bad rows are explained', function (string $csv, string $message) {
    $import = app(ImportCustomersFromCsv::class);
    try {
        $plan = $import->plan($csv);
        expect($plan['rows'][0]['detail'])->toBe($message);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toBe($message);
    }
})->with([
    'empty' => ['', 'The file is empty.'],
    'no header' => ["Kasoa,Central\n", 'The first row must be the column names (see the template); name and region are required.'],
    'unknown column' => ["name,region,colour\nA,Central,red\n", 'Unknown column(s): colour.'],
    'no rows' => ["name,region\n\n", 'The file has no data rows.'],
    'unknown region' => ["name,region\nA,Ashanty\n", 'unknown region "Ashanty"'],
    'bad type' => ["name,region,type\nA,Central,church\n", 'type must be school, reseller or individual (got "church")'],
    'zero balance' => ["name,region,opening_balance,opening_balance_due\nA,Central,0,2026-09-30\n", 'opening balance must be more than zero (leave it empty for none)'],
    'no due date' => ["name,region,opening_balance\nA,Central,10.00\n", 'opening balance needs a due date (opening_balance_due)'],
    'bad date' => ["name,region,opening_balance,opening_balance_date,opening_balance_due\nA,Central,10.00,31/08/2026,2026-09-30\n", 'opening balance date "31/08/2026" must be written YYYY-MM-DD'],
    'future date' => ["name,region,opening_balance,opening_balance_date,opening_balance_due\nA,Central,10.00,2026-12-01,2026-12-31\n", 'opening balance date is in the future'],
    'bad email' => ["name,region,email\nA,Central,not-an-email\n", 'email is not valid'],
]);

test('Excel byte order mark and quoted amounts are read', function () {
    $plan = app(ImportCustomersFromCsv::class)->plan("\xEF\xBB\xBFname,region,opening_balance,opening_balance_due\nA,central,\"1,000.50\",2026-09-30\n");

    expect($plan['opening_balance_total'])->toBe(100050)
        ->and($plan['rows'][0]['customer']['region'])->toBe('Central');
});

test('customers:import is a dry run unless --commit, and shows the total', function () {
    $path = tempnam(sys_get_temp_dir(), 'cus').'.csv';
    file_put_contents($path, OpeningBalanceFixture::CSV);

    $this->artisan('customers:import', ['file' => $path])
        ->expectsOutputToContain('To create: 3   Skipped: 2   Errors: 2')
        ->expectsOutputToContain('Total opening balances to create: GHS 3,700.00')
        ->assertFailed();
    $this->artisan('customers:import', ['file' => $path, '--commit' => true, '--owner' => $this->owner->email])
        ->expectsOutputToContain('Nothing imported: fix the 2 row(s) with errors first.')
        ->assertFailed();
    expect(Customer::query()->count())->toBe(1);

    file_put_contents($path, OpeningBalanceFixture::fixedCsv());
    $this->artisan('customers:import', ['file' => $path])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertSuccessful();
    expect(Customer::query()->count())->toBe(1);

    $this->artisan('customers:import', ['file' => $path, '--commit' => true, '--owner' => $this->owner->email])
        ->expectsOutputToContain('Imported 3 customer(s): CUS-0002, CUS-0003, CUS-0004.')
        ->assertSuccessful();
    expect(Customer::query()->count())->toBe(4);
    @unlink($path);
});
