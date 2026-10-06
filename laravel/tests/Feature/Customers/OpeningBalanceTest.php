<?php

use App\Actions\Customers\CustomerStatement;
use App\Actions\Reports\BestSellersReport;
use App\Actions\Reports\DashboardReport;
use App\Actions\Reports\ProfitReport;
use App\Actions\Reports\ReceivablesAgingReport;
use App\Actions\Reports\SalesSummaryReport;
use App\Actions\Sales\CreateOpeningBalance;
use App\Actions\Sales\VoidSale;
use App\Exceptions\OpeningBalanceExistsException;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\OpeningBalanceFixture;

/*
 * docs/acceptance-phase3.md 6.3 and 6.4, calculated by hand before the code.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = OpeningBalanceFixture::build($this->owner);
    $this->hilltop = $this->fx->customers['hilltop'];
    $this->bright = $this->fx->customers['bright'];
});

afterEach(fn () => Carbon::setTestNow());

test('6.3 balances after the import, a sale and a payment (the payment goes to the opening balance first)', function () {
    expect(Sale::query()->orderBy('id')->get()->map(fn (Sale $s) => [$s->invoice_no, $s->total, $s->amount_paid, $s->balance_due])->all())->toBe([
        ['OB-2026-000001', 250000, 100000, 150000],
        ['OB-2026-000002', 120000, 0, 120000],
        ['INV-2026-000003', 10000, 0, 10000],
    ])
        ->and(Customer::query()->orderBy('id')->pluck('outstanding_balance', 'name')->all())->toBe([
            'Existing Academy' => 0, 'Kasoa Hilltop School' => 160000, 'Winneba Bright Stars' => 120000, 'Osu Little Angels' => 0,
        ])
        ->and((int) Customer::query()->sum('credit_balance'))->toBe(0);
    assertMoneyInvariants();
    $this->artisan('customers:reconcile')->assertSuccessful();
});

test('6.3 statement: "Balance brought forward" on its date, then the invoice and the payment', function () {
    $s = app(CustomerStatement::class)->run($this->hilltop->fresh(), '2026-08-01', '2026-11-05');

    expect(array_map(fn ($l) => [$l['at'], $l['description'], $l['amount'], $l['balance'], $l['opening_balance']], $s['lines']))->toBe([
        ['2026-08-31T00:00:00+00:00', 'Balance brought forward (OB-2026-000001)', 250000, 250000, true],
        ['2026-09-10T10:00:00+00:00', 'Invoice INV-2026-000003', 10000, 260000, false],
        ['2026-09-20T12:00:00+00:00', 'Payment RCT-2026-000001 (cash)', -100000, 160000, false],
    ])
        ->and([$s['opening_balance'], $s['totals']['debits'], $s['totals']['credits'], $s['closing_balance']])->toBe([0, 260000, 100000, 160000])
        ->and($s['closing_balance'])->toBe($this->hilltop->fresh()->outstanding_balance - $this->hilltop->fresh()->credit_balance);

    $september = app(CustomerStatement::class)->run($this->hilltop->fresh(), '2026-09-01', '2026-11-05');
    expect([$september['opening_balance'], count($september['lines']), $september['closing_balance']])->toBe([250000, 2, 160000]);
});

test('6.3 aging as of 2026-11-05 includes the opening balances and says how much is brought forward', function () {
    $r = app(ReceivablesAgingReport::class)->run('2026-11-05');

    expect(array_map(fn ($row) => [$row['name'], $row['not_yet_due'], $row['days_1_30'], $row['days_31_60'], $row['days_61_90'], $row['days_90_plus'], $row['total'], $row['brought_forward']], $r['rows']))->toBe([
        ['Kasoa Hilltop School', 0, 10000, 150000, 0, 0, 160000, 150000],
        ['Winneba Bright Stars', 0, 120000, 0, 0, 0, 120000, 120000],
    ])
        ->and($r['totals'])->toBe(['not_yet_due' => 0, 'days_1_30' => 130000, 'days_31_60' => 150000, 'days_61_90' => 0, 'days_90_plus' => 0, 'total' => 280000, 'brought_forward' => 270000])
        ->and($r['totals']['total'])->toBe((int) Customer::query()->sum('outstanding_balance'));
});

test('6.3 reports and dashboard sales ignore opening balances; owed and overdue include them', function () {
    $summary = app(SalesSummaryReport::class);
    expect($summary->run('2026-08-01', '2026-08-31', 'month')['totals'])->toBe(['sales_count' => 0, 'gross' => 0, 'order_discounts' => 0, 'revenue' => 0, 'collections' => 0])
        ->and($summary->run('2026-09-01', '2026-09-30', 'month')['totals'])->toBe(['sales_count' => 1, 'gross' => 10000, 'order_discounts' => 0, 'revenue' => 10000, 'collections' => 100000])
        ->and($summary->run('2026-08-01', '2026-09-30', 'day', ['customer_id' => $this->hilltop->id])['totals']['revenue'])->toBe(10000);

    expect(app(ProfitReport::class)->run('2026-09-01', '2026-09-30', 'product')['totals'])
        ->toBe(['quantity' => 2, 'revenue' => 10000, 'cost' => 6000, 'gross_profit' => 4000, 'order_level_discounts' => 0, 'net_profit' => 4000])
        ->and(app(ProfitReport::class)->run('2026-08-01', '2026-08-31', 'period')['totals']['net_profit'])->toBe(0)
        ->and(array_map(fn ($r) => [$r['label'], $r['quantity'], $r['revenue']], app(BestSellersReport::class)->run('2026-08-01', '2026-09-30', 'product')['rows']))
        ->toBe([['Maths P4', 2, 10000]]);

    expect(app(DashboardReport::class)->run('2026-08-31'))->toMatchArray([
        'sales_today' => ['count' => 0, 'revenue' => 0],
        'sales_month' => ['count' => 0, 'revenue' => 0],
        'collections_today' => 0,
        'owed' => 280000,
        'overdue' => 0,
        'top_sellers' => [],
    ])
        ->and(app(DashboardReport::class)->run('2026-11-05')['overdue'])->toBe(280000);
});

test('6.4 one live opening balance per customer; voiding releases it', function () {
    expect(fn () => app(CreateOpeningBalance::class)->execute($this->owner, $this->hilltop, ['amount' => 5000, 'date' => '2026-09-01', 'due_date' => '2026-10-01']))
        ->toThrow(OpeningBalanceExistsException::class);
    expect(Sale::query()->count())->toBe(3);

    OpeningBalanceFixture::at('2026-09-25 09:00');
    $ob2 = Sale::query()->where('invoice_no', 'OB-2026-000002')->sole();
    app(VoidSale::class)->execute($this->owner, $ob2, 'Wrong amount on paper');
    expect($this->bright->fresh()->outstanding_balance)->toBe(0);

    $new = app(CreateOpeningBalance::class)->execute($this->owner, $this->bright, ['amount' => 80000, 'date' => '2026-08-31', 'due_date' => '2026-10-15']);
    expect($new->invoice_no)->toBe('OB-2026-000004')
        ->and($this->bright->fresh()->outstanding_balance)->toBe(80000);
    assertMoneyInvariants();
});

test('the database itself refuses a second live opening balance', function () {
    $sale = Sale::query()->where('invoice_no', 'OB-2026-000001')->sole()->replicate(['opening_balance_customer_id']);
    $sale->invoice_no = 'OB-TEST';

    expect(fn () => $sale->save())->toThrow(UniqueConstraintViolationException::class);
});

test('API: POST /customers/{id}/opening-balance is idempotent and owner only; errors are friendly', function () {
    Sanctum::actingAs($this->owner);
    $osu = $this->fx->customers['osu'];
    OpeningBalanceFixture::at('2026-09-25 09:00');

    $this->withHeader('Idempotency-Key', 'ob-1')
        ->postJson("/api/v1/customers/{$osu->id}/opening-balance", ['amount' => 45050, 'date' => '2026-08-31', 'due_date' => '2026-09-30'])
        ->assertCreated()
        ->assertJsonPath('data.invoice_no', 'OB-2026-000004')
        ->assertJsonPath('data.is_opening_balance', true)
        ->assertJsonPath('data.balance_due', 45050)
        ->assertJsonPath('data.items', []);
    $this->withHeader('Idempotency-Key', 'ob-1')
        ->postJson("/api/v1/customers/{$osu->id}/opening-balance", ['amount' => 45050, 'date' => '2026-08-31', 'due_date' => '2026-09-30'])
        ->assertCreated()->assertJsonPath('data.invoice_no', 'OB-2026-000004');
    $this->withHeader('Idempotency-Key', 'ob-2')
        ->postJson("/api/v1/customers/{$osu->id}/opening-balance", ['amount' => 100, 'date' => '2026-08-31', 'due_date' => '2026-09-30'])
        ->assertStatus(409)->assertJsonPath('code', 'opening_balance_exists')->assertJsonPath('details.invoice_no', 'OB-2026-000004');
    $this->withHeader('Idempotency-Key', 'ob-3')
        ->postJson("/api/v1/customers/{$this->fx->customers['existing']->id}/opening-balance", ['amount' => 0, 'date' => '2026-12-31', 'due_date' => 'soon'])
        ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'date', 'due_date']);
    expect($osu->fresh()->outstanding_balance)->toBe(45050);

    Sanctum::actingAs(User::factory()->create(['role' => 'school']));
    $this->withHeader('Idempotency-Key', 'ob-4')
        ->postJson("/api/v1/customers/{$osu->id}/opening-balance", ['amount' => 100, 'date' => '2026-08-31', 'due_date' => '2026-09-30'])
        ->assertForbidden();
});

test('admin: the customer page records an opening balance and hides the action once there is one', function () {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $osu = $this->fx->customers['osu'];
    OpeningBalanceFixture::at('2026-09-25 09:00');

    Livewire::test(EditCustomer::class, ['record' => $osu->getRouteKey()])
        ->assertActionVisible('openingBalance')
        ->callAction('openingBalance', data: ['amount' => '450.50', 'date' => '2026-08-31', 'due_date' => '2026-09-30'])
        ->assertHasNoActionErrors()
        ->assertNotified('Opening balance OB-2026-000004 recorded: GHS 450.50');

    expect($osu->fresh()->outstanding_balance)->toBe(45050);
    Livewire::test(EditCustomer::class, ['record' => $osu->getRouteKey()])->assertActionHidden('openingBalance');
});
