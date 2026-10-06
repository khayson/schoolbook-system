<?php

use App\Actions\Customers\CustomerStatement;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\VoidPayment;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\VoidSale;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReportsFixture;

/*
 * Every literal below is from docs/acceptance-phase3.md 3.9, calculated by hand before
 * the statement code existed. Change the code, never these numbers.
 */

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->fx = ReportsFixture::build($this->owner);
});

/** Statement lines as [type, s#/P#, amount, balance]. */
function statementLines(ReportsFixture $fx, array $statement): array
{
    $sales = array_flip(array_map(fn ($s) => $s->id, $fx->sales));
    $payments = array_flip(array_map(fn ($p) => $p->id, $fx->payments));

    return array_map(fn (array $l) => [
        $l['type'],
        $l['sale_id'] !== null ? 's'.$sales[$l['sale_id']] : $payments[$l['payment_id']],
        $l['amount'],
        $l['balance'],
    ], $statement['lines']);
}

function statementOf(ReportsFixture $fx, string $customer, string $from, string $to): array
{
    return app(CustomerStatement::class)->run($fx->customers[$customer]->fresh(), $from, $to);
}

/** [opening, debits, credits, closing] */
function statementTotals(array $s): array
{
    return [$s['opening_balance'], $s['totals']['debits'], $s['totals']['credits'], $s['closing_balance']];
}

test('3.9 (a) June: Alpha, Beta and Gamma', function () {
    $alpha = statementOf($this->fx, 'alpha', '2026-06-01', '2026-06-30');
    expect(statementLines($this->fx, $alpha))->toBe([
        ['invoice', 's6', 76000, 76000],
        ['payment', 'P2', -76000, 0],
        ['invoice', 's7', 7000, 7000],
        ['invoice', 's8', 5000, 12000],
        ['invoice', 's9', 12000, 24000],
        ['invoice_void', 's9', -12000, 12000],
        ['payment', 'P4', -1000, 11000],
        ['payment_void', 'P4', 1000, 12000],
    ])->and(statementTotals($alpha))->toBe([0, 101000, 89000, 12000]);

    $beta = statementOf($this->fx, 'beta', '2026-06-01', '2026-06-30');
    expect(statementLines($this->fx, $beta))->toBe([['invoice', 's5', 5000, 37000]])
        ->and(statementTotals($beta))->toBe([32000, 5000, 0, 37000]);

    $gamma = statementOf($this->fx, 'gamma', '2026-06-01', '2026-06-30');
    expect(statementLines($this->fx, $gamma))->toBe([
        ['invoice', 's10', 3000, 3000],
        ['payment', 'P3', -5000, -2000],
    ])->and(statementTotals($gamma))->toBe([0, 3000, 5000, -2000]);
});

test('3.9 (b) January to June', function () {
    $beta = statementOf($this->fx, 'beta', '2026-01-01', '2026-06-30');
    expect(statementLines($this->fx, $beta))->toBe([
        ['invoice', 's1', 12000, 12000],
        ['invoice', 's2', 12000, 24000],
        ['invoice', 's3', 6000, 30000],
        ['payment', 'P1', -2000, 28000],
        ['invoice', 's4', 4000, 32000],
        ['invoice', 's5', 5000, 37000],
    ])->and(statementTotals($beta))->toBe([0, 39000, 2000, 37000]);

    $alpha = statementOf($this->fx, 'alpha', '2026-01-01', '2026-06-30');
    expect(count($alpha['lines']))->toBe(8)
        ->and(statementTotals($alpha))->toBe([0, 101000, 89000, 12000]);

    $gamma = statementOf($this->fx, 'gamma', '2026-01-01', '2026-06-30');
    expect(count($gamma['lines']))->toBe(2)
        ->and(statementTotals($gamma))->toBe([0, 3000, 5000, -2000]);
});

test('3.9 (c) mid-dataset 2026-06-21 to 06-30: non-zero openings', function () {
    $alpha = statementOf($this->fx, 'alpha', '2026-06-21', '2026-06-30');
    expect(statementLines($this->fx, $alpha))->toBe([
        ['payment', 'P4', -1000, 11000],
        ['payment_void', 'P4', 1000, 12000],
    ])->and(statementTotals($alpha))->toBe([12000, 1000, 1000, 12000]);

    $beta = statementOf($this->fx, 'beta', '2026-06-21', '2026-06-30');
    expect($beta['lines'])->toBe([])
        ->and(statementTotals($beta))->toBe([37000, 0, 0, 37000]);

    $gamma = statementOf($this->fx, 'gamma', '2026-06-21', '2026-06-30');
    expect(statementLines($this->fx, $gamma))->toBe([['payment', 'P3', -5000, -2000]])
        ->and(statementTotals($gamma))->toBe([3000, 0, 5000, -2000]);
});

test('3.9 (d) timezone boundary: 23:30 is the 14th, 00:30 the 15th', function () {
    $day = statementOf($this->fx, 'alpha', '2026-06-15', '2026-06-15');
    expect(statementLines($this->fx, $day))->toBe([['invoice', 's8', 5000, 12000]])
        ->and(statementTotals($day))->toBe([7000, 5000, 0, 12000]);

    $before = statementOf($this->fx, 'alpha', '2026-06-01', '2026-06-14');
    expect(array_column(statementLines($this->fx, $before), 1))->toBe(['s6', 'P2', 's7'])
        ->and($before['closing_balance'])->toBe(7000);
});

test('3.9 (d) an event at exactly 00:00 belongs to that day, not to the opening balance', function () {
    $echo = Customer::factory()->create(['name' => 'Echo School', 'credit_limit' => null]);
    Carbon::setTestNow(Carbon::parse('2026-07-02 00:00:00', 'Africa/Accra'));
    $draft = app(CreateDraftSale::class)->execute($this->owner, ['customer_id' => $echo->id, 'items' => [['product_id' => $this->fx->products['A']->id, 'quantity' => 1]]]);
    app(ConfirmSale::class)->execute($this->owner, $draft, ['due_date' => '2026-08-01']);
    Carbon::setTestNow();

    $day = app(CustomerStatement::class)->run($echo->fresh(), '2026-07-02', '2026-07-02');
    expect(array_map(fn ($l) => [$l['type'], $l['amount'], $l['balance']], $day['lines']))->toBe([['invoice', 5000, 5000]])
        ->and(statementTotals($day))->toBe([0, 5000, 0, 5000]);

    $before = app(CustomerStatement::class)->run($echo->fresh(), '2026-07-01', '2026-07-01');
    expect($before['lines'])->toBe([])->and(statementTotals($before))->toBe([0, 0, 0, 0]);
});

test('3.9 (e) same timestamp: invoices, then payments, then invoice voids, then payment voids', function () {
    $delta = Customer::factory()->create(['name' => 'Delta School', 'credit_limit' => null]);
    Setting::setValue('allow_negative_stock', false);
    Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00', 'Africa/Accra'));

    // Recorded in a different order from the rule: payment first, voids reversed.
    $q = app(RecordPayment::class)->execute($this->owner, ['customer_id' => $delta->id, 'amount' => 4000, 'method' => 'cash', 'paid_at' => now()]);
    $draft = app(CreateDraftSale::class)->execute($this->owner, ['customer_id' => $delta->id, 'items' => [['product_id' => $this->fx->products['A']->id, 'quantity' => 2]]]);
    $x = app(ConfirmSale::class)->execute($this->owner, $draft, ['due_date' => '2026-07-31']);
    app(VoidPayment::class)->execute($this->owner, $q, 'Entered twice');
    app(VoidSale::class)->execute($this->owner, $x, 'Wrong school');
    Carbon::setTestNow();

    $s = app(CustomerStatement::class)->run($delta->fresh(), '2026-07-01', '2026-07-01');

    expect(array_map(fn ($l) => [$l['type'], $l['amount'], $l['balance']], $s['lines']))->toBe([
        ['invoice', 10000, 10000],
        ['payment', -4000, 6000],
        ['invoice_void', -10000, -4000],
        ['payment_void', 4000, 0],
    ])->and($s['closing_balance'])->toBe(0)
        ->and($delta->fresh()->outstanding_balance - $delta->fresh()->credit_balance)->toBe(0);
});

test('property: closing balance after the last event = outstanding_balance - credit_balance, every fixture customer', function () {
    $expected = ['alpha' => 12000, 'beta' => 37000, 'gamma' => -2000];

    foreach ($this->fx->customers as $key => $customer) {
        $customer->refresh();
        $statement = app(CustomerStatement::class)->run($customer, '2026-01-01', '2026-12-31');

        expect($statement['closing_balance'])
            ->toBe($customer->outstanding_balance - $customer->credit_balance)
            ->toBe($expected[$key]);

        // Any split point gives the same closing: opening carries everything before it.
        foreach (['2026-03-01', '2026-06-15', '2026-06-21', '2026-06-26'] as $from) {
            expect(app(CustomerStatement::class)->run($customer, $from, '2026-12-31')['closing_balance'])->toBe($expected[$key]);
        }
    }
});

test('statement lines carry references and readable descriptions', function () {
    $s = statementOf($this->fx, 'alpha', '2026-06-16', '2026-06-25');
    $s9 = $this->fx->sales[9];
    $p4 = $this->fx->payments['P4'];

    expect($s['lines'][0])->toMatchArray(['type' => 'invoice', 'reference' => $s9->invoice_no, 'description' => "Invoice {$s9->invoice_no}", 'debit' => 12000, 'credit' => 0, 'date' => '2026-06-16'])
        ->and($s['lines'][1]['description'])->toBe("Void of invoice {$s9->invoice_no}: Entered for the wrong school")
        ->and($s['lines'][2]['description'])->toBe("Payment {$p4->receipt_no} (cash)")
        ->and($s['lines'][3]['description'])->toBe("Void of payment {$p4->receipt_no}: Recorded twice")
        ->and($s['lines'][0]['at'])->toBe('2026-06-16T09:00:00+00:00');
});

// --- API -------------------------------------------------------------------------------

test('GET /customers/{id}/statement returns the figures as JSON', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/customers/{$this->fx->customers['gamma']->id}/statement?from=2026-06-21&to=2026-06-30")
        ->assertOk()
        ->assertJsonPath('data.opening_balance', 3000)
        ->assertJsonPath('data.closing_balance', -2000)
        ->assertJsonPath('data.totals', ['debits' => 0, 'credits' => 5000])
        ->assertJsonPath('data.lines.0.type', 'payment')
        ->assertJsonPath('data.customer.name', 'Gamma Academy');
});

test('format=pdf downloads a PDF', function () {
    Sanctum::actingAs($this->owner);
    $alpha = $this->fx->customers['alpha'];

    $response = $this->get("/api/v1/customers/{$alpha->id}/statement?from=2026-06-01&to=2026-06-30&format=pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(substr($response->getContent(), 0, 4))->toBe('%PDF')
        ->and($response->headers->get('content-disposition'))->toContain("statement-{$alpha->code}-2026-06-01-2026-06-30.pdf");
});

test('statement PDF shows the ledger figures and escapes user text', function () {
    $alpha = $this->fx->customers['alpha'];
    $alpha->forceFill(['name' => '<script>alert(1)</script> School'])->save();

    $html = view('pdf.statement', [
        'statement' => app(CustomerStatement::class)->run($alpha->fresh(), '2026-06-21', '2026-06-30'),
        'business' => ['name' => 'Kumasi <b>Book</b> Depot', 'address' => "Adum\nKumasi", 'phone' => '', 'footer' => 'Thank <i>you</i>'],
    ])->render();

    expect($html)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; School')
        ->toContain('Kumasi &lt;b&gt;Book&lt;/b&gt; Depot')
        ->toContain('Thank &lt;i&gt;you&lt;/i&gt;')
        ->toContain('Opening balance')
        ->toContain('GHS 120.00')
        ->toContain('GHS 110.00')
        ->toContain('GHS 10.00')
        ->toContain('Void of payment '.$this->fx->payments['P4']->receipt_no.': Recorded twice')
        ->not->toContain('<script>')
        ->not->toContain('<b>Book');

    $gamma = view('pdf.statement', [
        'statement' => app(CustomerStatement::class)->run($this->fx->customers['gamma']->fresh(), '2026-06-01', '2026-06-30'),
        'business' => ['name' => '', 'address' => '', 'phone' => '', 'footer' => ''],
    ])->render();
    expect($gamma)->toContain('GHS 20.00 CR');
});

test('statement parameters are validated', function (string $query, string $field) {
    Sanctum::actingAs($this->owner);

    $this->getJson("/api/v1/customers/{$this->fx->customers['alpha']->id}/statement?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'from missing' => ['to=2026-06-30', 'from'],
    'to before from' => ['from=2026-06-30&to=2026-06-01', 'to'],
    'bad date' => ['from=01/06/2026&to=2026-06-30', 'from'],
    'bad format' => ['from=2026-06-01&to=2026-06-30&format=xlsx', 'format'],
]);

test('statements are for the owner only', function () {
    Sanctum::actingAs(User::factory()->create(['role' => 'school']));

    $this->getJson("/api/v1/customers/{$this->fx->customers['alpha']->id}/statement?from=2026-06-01&to=2026-06-30")
        ->assertForbidden();
});
