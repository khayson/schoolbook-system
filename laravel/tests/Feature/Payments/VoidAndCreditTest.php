<?php

use App\Actions\Payments\AllocateCredit;
use App\Actions\Payments\VoidPayment;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\MarkDelivered;
use App\Actions\Sales\VoidSale;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Exceptions\AllocationExceedsCreditException;
use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\InvalidInputException;
use App\Exceptions\NoCreditAvailableException;
use App\Exceptions\PaymentAlreadyVoidException;
use App\Exceptions\SaleDeliveredException;
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

function creditInvoice(User $owner, Customer $customer, int $total, string $dueDate = '2026-10-20', bool $applyCredit = false): Sale
{
    $draft = makeDraftSale($owner, [[stockedProduct(stock: 100, price: $total), 1]], $customer);

    return app(ConfirmSale::class)->execute($owner, $draft, ['due_date' => $dueDate, 'apply_credit' => $applyCredit]);
}

// --- VoidPayment ---------------------------------------------------------------------------

test('voiding a payment reverses every allocation and removes its credit', function () {
    $a = creditInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $b = creditInvoice($this->owner, $this->customer, 2000, '2026-10-10');
    $payment = recordPayment($this->owner, $this->customer, 3500); // 1000 + 2000, 500 credit

    expect($this->customer->fresh()->credit_balance)->toBe(500);

    $voided = app(VoidPayment::class)->execute($this->owner, $payment, 'Cheque bounced');

    $reversals = PaymentAllocation::query()->whereNotNull('reversal_of_id')->orderBy('id')->get();

    expect($voided->status)->toBe(PaymentRecordStatus::Void)
        ->and($voided->void_reason)->toBe('Cheque bounced')
        ->and($voided->voided_by)->toBe($this->owner->id)
        ->and($voided->unallocated_amount)->toBe(0)
        ->and($reversals->pluck('amount')->all())->toBe([-1000, -2000])
        ->and($a->fresh()->balance_due)->toBe(1000)
        ->and($a->fresh()->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($b->fresh()->amount_paid)->toBe(0)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(3000)
        ->and($this->customer->fresh()->credit_balance)->toBe(0)
        // History stays: originals are untouched, only new rows were added.
        ->and(PaymentAllocation::query()->count())->toBe(4);
});

test('a void payment cannot be voided again and needs a reason', function () {
    $payment = recordPayment($this->owner, $this->customer, 500);

    expect(fn () => app(VoidPayment::class)->execute($this->owner, $payment, '  '))->toThrow(InvalidInputException::class);

    app(VoidPayment::class)->execute($this->owner, $payment, 'Duplicate entry');

    expect(fn () => app(VoidPayment::class)->execute($this->owner, $payment->fresh(), 'Again'))
        ->toThrow(PaymentAlreadyVoidException::class);
});

test('voiding a payment whose credit was later applied reverses that application too', function () {
    $invoice = creditInvoice($this->owner, $this->customer, 3000);
    $payment = recordPayment($this->owner, $this->customer, 5000, ['auto_allocate' => false]);
    app(AllocateCredit::class)->execute($this->owner, $this->customer, [['sale_id' => $invoice->id, 'amount' => 3000]]);

    expect($this->customer->fresh()->credit_balance)->toBe(2000);

    app(VoidPayment::class)->execute($this->owner, $payment->fresh(), 'Recorded against wrong school');

    expect($invoice->fresh()->balance_due)->toBe(3000)
        ->and($this->customer->fresh()->credit_balance)->toBe(0)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(3000);
});

// --- AllocateCredit ---------------------------------------------------------------------------

test('credit is applied oldest invoice first and consumed FIFO by paid_at', function () {
    $older = creditInvoice($this->owner, $this->customer, 1500, '2026-10-05');
    $newer = creditInvoice($this->owner, $this->customer, 1000, '2026-10-25');

    $recordedFirst = recordPayment($this->owner, $this->customer, 1000, ['auto_allocate' => false, 'paid_at' => '2026-09-01']);
    $paidEarlier = recordPayment($this->owner, $this->customer, 2000, ['auto_allocate' => false, 'paid_at' => '2026-08-15']);

    $result = app(AllocateCredit::class)->execute($this->owner, $this->customer);

    $rows = collect($result->allocations)->map(fn ($r) => [$r->payment_id, $r->sale_id, $r->amount])->all();

    expect($result->appliedTotal)->toBe(2500)
        ->and($rows)->toBe([
            [$paidEarlier->id, $older->id, 1500],
            [$paidEarlier->id, $newer->id, 500],
            [$recordedFirst->id, $newer->id, 500],
        ])
        ->and($paidEarlier->fresh()->unallocated_amount)->toBe(0)
        ->and($recordedFirst->fresh()->unallocated_amount)->toBe(500)
        ->and($result->customer->credit_balance)->toBe(500)
        ->and($result->customer->outstanding_balance)->toBe(0);
});

test('explicit credit allocation targets the chosen invoice only', function () {
    $older = creditInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $chosen = creditInvoice($this->owner, $this->customer, 1000, '2026-11-05');
    recordPayment($this->owner, $this->customer, 600, ['auto_allocate' => false]);

    app(AllocateCredit::class)->execute($this->owner, $this->customer, [['sale_id' => $chosen->id, 'amount' => 600]]);

    expect($older->fresh()->balance_due)->toBe(1000)
        ->and($chosen->fresh()->balance_due)->toBe(400);
});

test('credit errors: none available, or more requested than held', function () {
    $invoice = creditInvoice($this->owner, $this->customer, 1000);

    expect(fn () => app(AllocateCredit::class)->execute($this->owner, $this->customer))
        ->toThrow(NoCreditAvailableException::class);

    recordPayment($this->owner, $this->customer, 300, ['auto_allocate' => false]);

    expect(fn () => app(AllocateCredit::class)->execute($this->owner, $this->customer, [['sale_id' => $invoice->id, 'amount' => 301]]))
        ->toThrow(AllocationExceedsCreditException::class);

    expect($invoice->fresh()->balance_due)->toBe(1000);
});

test('credit larger than everything owed leaves the remainder as credit', function () {
    creditInvoice($this->owner, $this->customer, 700);
    recordPayment($this->owner, $this->customer, 1000, ['auto_allocate' => false]);

    $result = app(AllocateCredit::class)->execute($this->owner, $this->customer);

    expect($result->appliedTotal)->toBe(700)
        ->and($result->customer->credit_balance)->toBe(300)
        ->and($result->customer->outstanding_balance)->toBe(0);
});

// --- Confirm with apply_credit ------------------------------------------------------------------

test('overpayment -> credit -> AllocateCredit FIFO -> confirm with apply_credit', function () {
    $first = creditInvoice($this->owner, $this->customer, 2000);
    recordPayment($this->owner, $this->customer, 3500); // 2000 to the invoice, 1500 credit

    $second = creditInvoice($this->owner, $this->customer, 1000);
    app(AllocateCredit::class)->execute($this->owner, $this->customer);   // 1000 of the credit

    expect($first->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($second->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($this->customer->fresh()->credit_balance)->toBe(500);

    $third = creditInvoice($this->owner, $this->customer, 1200, applyCredit: true);

    expect($third->status)->toBe(SaleStatus::Confirmed)
        ->and($third->amount_paid)->toBe(500)
        ->and($third->balance_due)->toBe(700)
        ->and($third->payment_status)->toBe(PaymentStatus::Partial)
        ->and($third->allocations)->toHaveCount(1)
        ->and($this->customer->fresh()->credit_balance)->toBe(0)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(700);
});

test('apply_credit fully pays a small invoice and is a no-op without credit', function () {
    recordPayment($this->owner, $this->customer, 2000);

    $paid = creditInvoice($this->owner, $this->customer, 800, applyCredit: true);
    expect($paid->payment_status)->toBe(PaymentStatus::Paid)
        ->and($this->customer->fresh()->credit_balance)->toBe(1200);

    $other = Customer::factory()->create(['credit_limit' => null]);
    $unpaid = creditInvoice($this->owner, $other, 800, applyCredit: true);
    expect($unpaid->payment_status)->toBe(PaymentStatus::Unpaid)
        ->and($unpaid->allocations)->toHaveCount(0);
});

test('credit applied on confirm reduces the credit-limit exposure', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    recordPayment($this->owner, $customer, 600);
    $draft = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1500), 1]], $customer);

    try {
        app(ConfirmSale::class)->execute($this->owner, $draft);
        $this->fail('Expected credit warning without apply_credit');
    } catch (CreditLimitExceededException $e) {
        expect($e->details()['projected_balance'])->toBe(1500);
    }

    $sale = app(ConfirmSale::class)->execute($this->owner, $draft, ['apply_credit' => true]);

    expect($sale->balance_due)->toBe(900)
        ->and($customer->fresh()->outstanding_balance)->toBe(900);
});

// --- VoidSale with payments -----------------------------------------------------------------------

test('voiding a sale paid by two payments returns each amount to its own payment', function () {
    $sale = creditInvoice($this->owner, $this->customer, 2000);
    $other = creditInvoice($this->owner, $this->customer, 1500, '2026-12-01');
    $p1 = recordPayment($this->owner, $this->customer, 500, ['allocations' => [['sale_id' => $sale->id, 'amount' => 500]]]);
    $p2 = recordPayment($this->owner, $this->customer, 700, ['allocations' => [['sale_id' => $sale->id, 'amount' => 700]]]);

    $voided = app(VoidSale::class)->execute($this->owner, $sale, 'Ordered in error');

    expect($voided->status)->toBe(SaleStatus::Void)
        ->and($voided->amount_paid)->toBe(0)
        ->and($voided->balance_due)->toBe(0)
        ->and(PaymentAllocation::query()->where('sale_id', $sale->id)->sum('amount'))->toEqual(0)
        ->and($p1->fresh()->unallocated_amount)->toBe(500)
        ->and($p2->fresh()->unallocated_amount)->toBe(700)
        ->and($this->customer->fresh()->credit_balance)->toBe(1200)
        ->and($this->customer->fresh()->outstanding_balance)->toBe(1500);

    // The released credit can pay the other invoice.
    app(AllocateCredit::class)->execute($this->owner, $this->customer);

    expect($other->fresh()->balance_due)->toBe(300)
        ->and($this->customer->fresh()->credit_balance)->toBe(0);
});

test('a delivered sale with payments still cannot be voided', function () {
    $sale = creditInvoice($this->owner, $this->customer, 1000);
    recordPayment($this->owner, $this->customer, 400);
    app(MarkDelivered::class)->execute($this->owner, $sale);

    expect(fn () => app(VoidSale::class)->execute($this->owner, $sale->fresh(), 'Too late'))
        ->toThrow(SaleDeliveredException::class);

    expect($sale->fresh()->amount_paid)->toBe(400);
});

test('voiding a payment after its sale was voided only removes the credit', function () {
    $sale = creditInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 1000);
    app(VoidSale::class)->execute($this->owner, $sale, 'Wrong order');

    app(VoidPayment::class)->execute($this->owner, $payment->fresh(), 'Refunded in cash');

    expect(PaymentAllocation::query()->count())->toBe(2) // original + the sale-void reversal only
        ->and($this->customer->fresh()->credit_balance)->toBe(0)
        ->and($payment->fresh()->unallocated_amount)->toBe(0);
});
