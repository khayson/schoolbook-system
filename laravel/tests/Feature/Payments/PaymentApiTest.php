<?php

use App\Actions\Payments\RenderReceiptPdf;
use App\Actions\Payments\VoidPayment;
use App\Actions\Sales\ConfirmSale;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    Setting::setValue('business_name', 'Kumasi Book Depot');
    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
    $this->customer = Customer::factory()->create(['credit_limit' => null, 'name' => 'Akwaaba Basic School']);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

function apiInvoice(User $owner, Customer $customer, int $total): Sale
{
    return app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[stockedProduct(stock: 100, price: $total), 1]], $customer));
}

function postPayment($test, array $body, string $key = 'pay-1')
{
    return $test->withToken($test->token)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/payments', $body);
}

// --- POST /payments ------------------------------------------------------------------------------

test('recording a payment returns 201 with its allocations', function () {
    $invoice = apiInvoice($this->owner, $this->customer, 3000);

    postPayment($this, [
        'customer_id' => $this->customer->id,
        'amount' => 3500,
        'method' => 'momo',
        'reference' => 'MP261002.0001',
        'paid_at' => '2026-10-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.receipt_no', 'RCT-2026-000001')
        ->assertJsonPath('data.amount', 3500)
        ->assertJsonPath('data.method', 'momo')
        ->assertJsonPath('data.unallocated_amount', 500)
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.allocations.0.sale_id', $invoice->id)
        ->assertJsonPath('data.allocations.0.invoice_no', $invoice->invoice_no)
        ->assertJsonPath('data.allocations.0.amount', 3000)
        ->assertJsonPath('data.customer.credit_balance', 500)
        ->assertJsonPath('data.customer.outstanding_balance', 0);
});

test('same Idempotency-Key and body replays; a different body is refused', function () {
    apiInvoice($this->owner, $this->customer, 1000);
    $body = ['customer_id' => $this->customer->id, 'amount' => 400, 'method' => 'cash'];

    $first = postPayment($this, $body, 'same')->assertCreated();
    $replay = postPayment($this, $body, 'same')->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($replay->json('data.receipt_no'))->toBe($first->json('data.receipt_no'))
        ->and(Payment::query()->count())->toBe(1);

    postPayment($this, [...$body, 'amount' => 500], 'same')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_mismatch');

    // withHeader() persists on the test case; clear it to send no key at all.
    $this->flushHeaders()->withToken($this->token)->postJson('/api/v1/payments', $body)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_required');

    expect(Payment::query()->count())->toBe(1);
});

test('payment validation', function (array $body, string $field) {
    postPayment($this, [
        'customer_id' => $this->customer->id,
        'amount' => 1000,
        'method' => 'cash',
        ...$body,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors($field);

    expect(Payment::query()->count())->toBe(0);
})->with([
    'zero amount' => [['amount' => 0], 'amount'],
    'negative amount' => [['amount' => -5], 'amount'],
    'decimal amount' => [['amount' => 10.5], 'amount'],
    'momo needs reference' => [['method' => 'momo'], 'reference'],
    'bank transfer needs reference' => [['method' => 'bank_transfer'], 'reference'],
    'cheque needs reference' => [['method' => 'cheque', 'reference' => ''], 'reference'],
    'unknown method' => [['method' => 'card'], 'method'],
    'future paid_at' => [['paid_at' => '2026-10-05'], 'paid_at'],
    'duplicate sale ids' => [['allocations' => [['sale_id' => 1, 'amount' => 1], ['sale_id' => 1, 'amount' => 1]]], 'allocations.0.sale_id'],
    'zero allocation' => [['allocations' => [['sale_id' => 1, 'amount' => 0]]], 'allocations.0.amount'],
    'unknown customer' => [['customer_id' => 999999], 'customer_id'],
    'absurd amount' => [['amount' => 100_000_000_001], 'amount'],
    'absurd allocation' => [['allocations' => [['sale_id' => 1, 'amount' => 100_000_000_001]]], 'allocations.0.amount'],
]);

test('business-rule failures use their documented codes', function () {
    $invoice = apiInvoice($this->owner, $this->customer, 1000);
    $foreign = apiInvoice($this->owner, Customer::factory()->create(['credit_limit' => null]), 1000);

    postPayment($this, ['customer_id' => $this->customer->id, 'amount' => 2000, 'method' => 'cash', 'allocations' => [['sale_id' => $invoice->id, 'amount' => 1500]]], 'a')
        ->assertUnprocessable()->assertJsonPath('code', 'allocation_exceeds_balance')->assertJsonPath('details.balance_due', 1000);

    postPayment($this, ['customer_id' => $this->customer->id, 'amount' => 500, 'method' => 'cash', 'allocations' => [['sale_id' => $invoice->id, 'amount' => 600]]], 'b')
        ->assertUnprocessable()->assertJsonPath('code', 'allocation_exceeds_payment')->assertJsonPath('details.allocated_total', 600);

    postPayment($this, ['customer_id' => $this->customer->id, 'amount' => 500, 'method' => 'cash', 'allocations' => [['sale_id' => $foreign->id, 'amount' => 100]]], 'c')
        ->assertUnprocessable()->assertJsonPath('code', 'sale_not_payable')->assertJsonPath('details.reason', 'other_customer');

    expect(Payment::query()->count())->toBe(0);
});

// --- GET /payments, /payments/{id} -----------------------------------------------------------------

test('payments list filters by customer, method, status and date range', function () {
    $other = Customer::factory()->create(['credit_limit' => null]);
    recordPayment($this->owner, $this->customer, 100, ['paid_at' => '2026-09-01']);
    recordPayment($this->owner, $this->customer, 200, ['method' => 'momo', 'reference' => 'R1', 'paid_at' => '2026-09-15']);
    $voided = recordPayment($this->owner, $this->customer, 300, ['paid_at' => '2026-09-30']);
    recordPayment($this->owner, $other, 400, ['paid_at' => '2026-09-20']);
    app(VoidPayment::class)->execute($this->owner, $voided, 'Mistake');

    $amounts = fn (string $query) => collect($this->withToken($this->token)->getJson("/api/v1/payments?{$query}")->assertOk()->json('data'))
        ->pluck('amount')->all();

    expect($amounts("customer_id={$this->customer->id}"))->toBe([300, 200, 100])
        ->and($amounts('method=momo'))->toBe([200])
        ->and($amounts('status=void'))->toBe([300])
        ->and($amounts('from=2026-09-10&to=2026-09-20'))->toBe([400, 200]);

    $this->withToken($this->token)->getJson('/api/v1/payments?to=2026-09-01&from=2026-09-10')
        ->assertUnprocessable()->assertJsonValidationErrors('to');
});

test('payment detail and sale detail both show the allocation ledger', function () {
    $invoice = apiInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 600);

    $this->withToken($this->token)->getJson("/api/v1/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('data.allocations.0.invoice_no', $invoice->invoice_no)
        ->assertJsonPath('data.allocations.0.amount', 600);

    $this->withToken($this->token)->getJson("/api/v1/sales/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('data.amount_paid', 600)
        ->assertJsonPath('data.payment_status', 'partial')
        ->assertJsonPath('data.allocations.0.receipt_no', $payment->receipt_no)
        ->assertJsonPath('data.allocations.0.amount', 600);
});

// --- Void, apply credit, confirm with credit -----------------------------------------------------------

test('void payment endpoint reverses and is not repeatable', function () {
    apiInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 1000);

    $this->withToken($this->token)->postJson("/api/v1/payments/{$payment->id}/void", [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->withToken($this->token)->postJson("/api/v1/payments/{$payment->id}/void", ['reason' => 'Bounced'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.unallocated_amount', 0)
        ->assertJsonCount(2, 'data.allocations');

    $this->withToken($this->token)->postJson("/api/v1/payments/{$payment->id}/void", ['reason' => 'Again'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'payment_already_void')
        ->assertJsonPath('details.action', 'void');
});

test('apply-credit endpoint applies oldest first and is idempotent', function () {
    $invoice = apiInvoice($this->owner, $this->customer, 1000);
    recordPayment($this->owner, $this->customer, 700, ['auto_allocate' => false]);

    $call = fn (string $key, array $body = []) => $this->withToken($this->token)->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customers/{$this->customer->id}/apply-credit", $body);

    $call('credit-1')
        ->assertOk()
        ->assertJsonPath('data.applied_total', 700)
        ->assertJsonPath('data.customer.credit_balance', 0)
        ->assertJsonPath('data.customer.outstanding_balance', 300)
        ->assertJsonPath('data.allocations.0.invoice_no', $invoice->invoice_no);

    $call('credit-1')->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    $call('credit-2')
        ->assertStatus(409)
        ->assertJsonPath('code', 'no_credit_available');

    recordPayment($this->owner, $this->customer, 100, ['auto_allocate' => false]);
    $call('credit-3', ['allocations' => [['sale_id' => $invoice->id, 'amount' => 200]]])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'allocation_exceeds_credit');

    expect($invoice->fresh()->balance_due)->toBe(300);
});

test('confirm accepts apply_credit', function () {
    recordPayment($this->owner, $this->customer, 1500);
    $draft = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1000), 1]], $this->customer);

    $this->withToken($this->token)->withHeader('Idempotency-Key', 'confirm-credit')
        ->postJson("/api/v1/sales/{$draft->id}/confirm", ['apply_credit' => true])
        ->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.balance_due', 0)
        ->assertJsonPath('data.allocations.0.amount', 1000);

    expect($this->customer->fresh()->credit_balance)->toBe(500);
});

// --- Receipt PDF -------------------------------------------------------------------------------------

test('receipt pdf downloads for a valid payment and is refused once void', function () {
    apiInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 1000);

    $response = $this->withToken($this->token)->get("/api/v1/payments/{$payment->id}/receipt")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->headers->get('content-disposition'))->toContain('RCT-2026-000001.pdf')
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');

    app(VoidPayment::class)->execute($this->owner, $payment, 'Bounced');

    $this->withToken($this->token)->getJson("/api/v1/payments/{$payment->id}/receipt")
        ->assertStatus(409)
        ->assertJsonPath('code', 'payment_already_void')
        ->assertJsonPath('details.action', 'receipt');
});

test('receipt shows the per-invoice breakdown and escapes user text', function () {
    $customer = Customer::factory()->create(['credit_limit' => null, 'name' => '<script>alert(1)</script> School']);
    $a = apiInvoice($this->owner, $customer, 123450);
    $b = apiInvoice($this->owner, $customer, 50000);
    $payment = recordPayment($this->owner, $customer, 150000, [
        'method' => 'cheque',
        'reference' => 'CHQ <b>0042</b>',
        'notes' => 'Paid by <i>bursar</i>',
    ]);

    $html = view('pdf.receipt', [
        'payment' => $payment->fresh(['customer', 'receivedBy']),
        'lines' => collect([
            ['invoice_no' => $a->invoice_no, 'amount' => 123450, 'balance_due' => 0],
            ['invoice_no' => $b->invoice_no, 'amount' => 26550, 'balance_due' => 23450],
        ]),
        'business' => ['name' => 'Kumasi Book Depot', 'address' => '', 'phone' => '', 'footer' => ''],
    ])->render();

    expect($html)
        ->toContain('RCT-2026-000001')
        ->toContain('GHS 1,500.00')
        ->toContain($a->invoice_no)
        ->toContain('GHS 1,234.50')
        ->toContain('GHS 265.50')
        ->toContain('GHS 234.50')
        ->toContain('Cheque')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; School')
        ->toContain('CHQ &lt;b&gt;0042&lt;/b&gt;')
        ->toContain('Paid by &lt;i&gt;bursar&lt;/i&gt;')
        ->not->toContain('<script>')
        ->not->toContain('<b>0042');

    // The action computes the same breakdown from the ledger.
    expect(app(RenderReceiptPdf::class)->execute($payment))->not->toBeNull();
});

// --- Authorization ---------------------------------------------------------------------------------------

dataset('payment endpoints', [
    'list' => ['GET', '/api/v1/payments'],
    'store' => ['POST', '/api/v1/payments'],
    'show' => ['GET', '/api/v1/payments/{payment}'],
    'void' => ['POST', '/api/v1/payments/{payment}/void'],
    'receipt' => ['GET', '/api/v1/payments/{payment}/receipt'],
    'apply credit' => ['POST', '/api/v1/customers/{customer}/apply-credit'],
]);

test('non-owners get 403 on payment endpoints', function (string $method, string $uri) {
    $payment = recordPayment($this->owner, $this->customer, 500);
    $school = User::factory()->school()->create();

    $this->withToken($school->createToken('t')->plainTextToken)
        ->withHeader('Idempotency-Key', 'k')
        ->json($method, str_replace(['{payment}', '{customer}'], [$payment->id, $this->customer->id], $uri), [
            'customer_id' => $this->customer->id, 'amount' => 100, 'method' => 'cash', 'reason' => 'x',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect(Payment::query()->count())->toBe(1)
        ->and($payment->fresh()->isValid())->toBeTrue();
})->with('payment endpoints');

test('unauthenticated requests get 401 on payment endpoints', function (string $method, string $uri) {
    $payment = recordPayment($this->owner, $this->customer, 500);

    $this->withHeader('Idempotency-Key', 'k')
        ->json($method, str_replace(['{payment}', '{customer}'], [$payment->id, $this->customer->id], $uri))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
})->with('payment endpoints');

// --- Schema housekeeping ----------------------------------------------------------------------------------

test('sales.idempotency_key has been dropped', function () {
    expect(Schema::hasColumn('sales', 'idempotency_key'))->toBeFalse();
});

test('apply-credit rejects an absurd allocation amount', function () {
    recordPayment($this->owner, $this->customer, 100, ['auto_allocate' => false]);

    $this->withToken($this->token)->withHeader('Idempotency-Key', 'big')
        ->postJson("/api/v1/customers/{$this->customer->id}/apply-credit", ['allocations' => [['sale_id' => 1, 'amount' => 100_000_000_001]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allocations.0.amount');
});

test('apply-credit with credit but no open invoices succeeds with applied_total 0', function () {
    recordPayment($this->owner, $this->customer, 900, ['auto_allocate' => false]);

    $this->withToken($this->token)->withHeader('Idempotency-Key', 'nothing-open')
        ->postJson("/api/v1/customers/{$this->customer->id}/apply-credit")
        ->assertOk()
        ->assertJsonPath('data.applied_total', 0)
        ->assertJsonPath('data.allocations', [])
        ->assertJsonPath('data.customer.credit_balance', 900);
});
