<?php

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\VoidSale;
use App\Enums\PaymentStatus;
use App\Exceptions\AllocationExceedsBalanceException;
use App\Exceptions\AllocationExceedsPaymentException;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleNotPayableException;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    $this->owner = User::factory()->owner()->create();
    $this->customer = Customer::factory()->create(['credit_limit' => null]);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

/**
 * A confirmed invoice of exactly $total for the customer.
 */
function paymentInvoice(User $owner, Customer $customer, int $total, string $saleDate = '2026-09-20', string $dueDate = '2026-10-20'): Sale
{
    $draft = makeDraftSale($owner, [[stockedProduct(stock: 100, price: $total), 1]], $customer, ['sale_date' => $saleDate]);

    return app(ConfirmSale::class)->execute($owner, $draft, ['due_date' => $dueDate]);
}

function allocationsFor(Sale $sale): array
{
    return PaymentAllocation::query()->where('sale_id', $sale->id)->orderBy('id')->pluck('amount')->all();
}

// --- Auto allocation ----------------------------------------------------------------------

test('auto allocation pays oldest-due first: due date, then sale date, then id', function () {
    $later = paymentInvoice($this->owner, $this->customer, 3000, '2026-09-20', '2026-11-30');
    $sameDueNewer = paymentInvoice($this->owner, $this->customer, 2000, '2026-09-25', '2026-10-15');
    $sameDueOlder = paymentInvoice($this->owner, $this->customer, 1000, '2026-09-10', '2026-10-15');

    $payment = recordPayment($this->owner, $this->customer, 4500);

    expect($payment->allocations->pluck('sale_id')->all())->toBe([$sameDueOlder->id, $sameDueNewer->id, $later->id])
        ->and($payment->allocations->pluck('amount')->all())->toBe([1000, 2000, 1500])
        ->and($payment->unallocated_amount)->toBe(0)
        ->and($sameDueOlder->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($sameDueNewer->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($later->fresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and($later->fresh()->balance_due)->toBe(1500)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(1500)
        ->and($this->customer->fresh()->credit_balance)->toBe(0);
});

test('partial then full payment moves an invoice unpaid -> partial -> paid', function () {
    $invoice = paymentInvoice($this->owner, $this->customer, 5000);
    expect($invoice->payment_status)->toBe(PaymentStatus::Unpaid);

    recordPayment($this->owner, $this->customer, 2000);
    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Partial)
        ->and($invoice->fresh()->amount_paid)->toBe(2000);

    recordPayment($this->owner, $this->customer, 3000);
    expect($invoice->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($invoice->fresh()->balance_due)->toBe(0)
        ->and(allocationsFor($invoice))->toBe([2000, 3000]);
});

test('an overpayment keeps the remainder as customer credit', function () {
    $invoice = paymentInvoice($this->owner, $this->customer, 1000);

    $payment = recordPayment($this->owner, $this->customer, 1600);

    expect($invoice->fresh()->balance_due)->toBe(0)
        ->and($payment->unallocated_amount)->toBe(600)
        ->and($this->customer->fresh()->credit_balance)->toBe(600)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(0);
});

test('auto_allocate false puts the whole payment on credit', function () {
    $invoice = paymentInvoice($this->owner, $this->customer, 1000);

    $payment = recordPayment($this->owner, $this->customer, 800, ['auto_allocate' => false]);

    expect($payment->allocations)->toHaveCount(0)
        ->and($payment->unallocated_amount)->toBe(800)
        ->and($invoice->fresh()->balance_due)->toBe(1000)
        ->and($this->customer->fresh()->credit_balance)->toBe(800);
});

test('a payment with no open invoices is all credit', function () {
    $payment = recordPayment($this->owner, $this->customer, 2500);

    expect($payment->unallocated_amount)->toBe(2500)
        ->and($this->customer->fresh()->credit_balance)->toBe(2500);
});

// --- Explicit allocation --------------------------------------------------------------------

test('explicit allocations pay exactly the chosen invoices, the rest becomes credit', function () {
    $a = paymentInvoice($this->owner, $this->customer, 3000, dueDate: '2026-10-05');
    $b = paymentInvoice($this->owner, $this->customer, 2000, dueDate: '2026-12-01');

    $payment = recordPayment($this->owner, $this->customer, 2500, ['allocations' => [
        ['sale_id' => $b->id, 'amount' => 2000],
        ['sale_id' => $a->id, 'amount' => 100],
    ]]);

    expect(allocationsFor($b))->toBe([2000])
        ->and(allocationsFor($a))->toBe([100])
        ->and($payment->unallocated_amount)->toBe(400)
        ->and($this->customer->fresh()->credit_balance)->toBe(400)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(2900);
});

test('explicit allocation errors write nothing', function (Closure $allocations, string $exception, ?string $reason) {
    $invoice = paymentInvoice($this->owner, $this->customer, 1000);
    $other = paymentInvoice($this->owner, Customer::factory()->create(['credit_limit' => null]), 1000);
    $draft = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]], $this->customer);
    $cancelled = app(CancelSale::class)->execute($this->owner, makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]], $this->customer));
    $voided = app(VoidSale::class)->execute($this->owner, paymentInvoice($this->owner, $this->customer, 700), 'Test');
    $paid = paymentInvoice($this->owner, $this->customer, 300);
    recordPayment($this->owner, $this->customer, 300, ['allocations' => [['sale_id' => $paid->id, 'amount' => 300]]]);

    $before = PaymentAllocation::query()->count();
    $ids = compact('invoice', 'other', 'draft', 'cancelled', 'voided', 'paid');

    try {
        recordPayment($this->owner, $this->customer, 1000, ['allocations' => $allocations($ids)]);
        $this->fail("Expected {$exception}");
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($reason !== null) {
            expect($e->details()['reason'])->toBe($reason);
        }
    }

    expect(PaymentAllocation::query()->count())->toBe($before)
        ->and($invoice->fresh()->balance_due)->toBe(1000);
})->with([
    'more than balance' => [fn ($s) => [['sale_id' => $s['invoice']->id, 'amount' => 1001]], AllocationExceedsBalanceException::class, null],
    'other customer' => [fn ($s) => [['sale_id' => $s['other']->id, 'amount' => 100]], SaleNotPayableException::class, 'other_customer'],
    'draft' => [fn ($s) => [['sale_id' => $s['draft']->id, 'amount' => 100]], SaleNotPayableException::class, 'not_confirmed'],
    'cancelled' => [fn ($s) => [['sale_id' => $s['cancelled']->id, 'amount' => 100]], SaleNotPayableException::class, 'not_confirmed'],
    'void' => [fn ($s) => [['sale_id' => $s['voided']->id, 'amount' => 100]], SaleNotPayableException::class, 'not_confirmed'],
    'fully paid' => [fn ($s) => [['sale_id' => $s['paid']->id, 'amount' => 100]], SaleNotPayableException::class, 'fully_paid'],
    'unknown sale' => [fn ($s) => [['sale_id' => 999999, 'amount' => 100]], SaleNotPayableException::class, 'not_found'],
]);

test('allocations adding up to more than the payment are refused', function () {
    $a = paymentInvoice($this->owner, $this->customer, 1000);
    $b = paymentInvoice($this->owner, $this->customer, 1000);

    expect(fn () => recordPayment($this->owner, $this->customer, 1500, ['allocations' => [
        ['sale_id' => $a->id, 'amount' => 1000],
        ['sale_id' => $b->id, 'amount' => 600],
    ]]))->toThrow(AllocationExceedsPaymentException::class);

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and($this->customer->fresh()->credit_balance)->toBe(0);
});

// --- Validation inside the action (second line of defence) -----------------------------------

test('the action rejects bad input as 422-mapped exceptions', function (array $extra, int $amount, string $field) {
    try {
        recordPayment($this->owner, $this->customer, $amount, $extra);
        $this->fail('Expected InvalidInputException');
    } catch (InvalidInputException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'zero amount' => [[], 0, 'amount'],
    'momo without reference' => [['method' => 'momo'], 100, 'reference'],
    'cheque with blank reference' => [['method' => 'cheque', 'reference' => '   '], 100, 'reference'],
    'unknown method' => [['method' => 'bitcoin'], 100, 'method'],
    'future date' => [['paid_at' => '2026-10-03'], 100, 'paid_at'],
    'absurd amount' => [[], 100_000_000_001, 'amount'],
]);

test('cash needs no reference; non-cash keeps its reference', function () {
    $cash = recordPayment($this->owner, $this->customer, 100);
    $momo = recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => 'MP261002.1234.A']);

    expect($cash->reference)->toBeNull()
        ->and($momo->reference)->toBe('MP261002.1234.A')
        ->and($momo->method->value)->toBe('momo');
});

// --- Receipt numbers -------------------------------------------------------------------------------

test('receipt numbers are sequential and use the recording year, not paid_at', function () {
    Carbon::setTestNow('2026-01-02 09:00:00');

    $first = recordPayment($this->owner, $this->customer, 100, ['paid_at' => '2025-12-30']);
    $second = recordPayment($this->owner, $this->customer, 100);

    expect($first->receipt_no)->toBe('RCT-2026-000001')
        ->and($first->paid_at->toDateString())->toBe('2025-12-30')
        ->and($second->receipt_no)->toBe('RCT-2026-000002');
});

test('a failed payment does not burn a receipt number', function () {
    $invoice = paymentInvoice($this->owner, $this->customer, 1000);

    expect(fn () => recordPayment($this->owner, $this->customer, 500, ['allocations' => [['sale_id' => $invoice->id, 'amount' => 600]]]))
        ->toThrow(AllocationExceedsPaymentException::class);

    expect(recordPayment($this->owner, $this->customer, 500)->receipt_no)->toBe('RCT-2026-000001');
});

test('a phone clock up to 10 minutes fast is tolerated and stored as server time', function () {
    $payment = recordPayment($this->owner, $this->customer, 100, ['paid_at' => '2026-10-02 10:09:30']);

    expect($payment->paid_at->format('Y-m-d H:i:s'))->toBe('2026-10-02 10:00:00');

    expect(fn () => recordPayment($this->owner, $this->customer, 100, ['paid_at' => '2026-10-02 10:10:30']))
        ->toThrow(InvalidInputException::class);
});

test('the API applies the same 10-minute tolerance', function () {
    $token = $this->owner->createToken('t')->plainTextToken;
    $post = fn (string $paidAt, string $key) => $this->withToken($token)->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/payments', ['customer_id' => $this->customer->id, 'amount' => 100, 'method' => 'cash', 'paid_at' => $paidAt]);

    $post('2026-10-02T10:09:00+00:00', 'skew-ok')->assertCreated();
    $post('2026-10-02T10:11:00+00:00', 'skew-bad')->assertUnprocessable()->assertJsonValidationErrors('paid_at');
});
