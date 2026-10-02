<?php

use App\Actions\Payments\VoidPayment;
use App\Enums\PaymentMethod;
use App\Exceptions\DuplicatePaymentReferenceException;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('t')->plainTextToken;
    $this->customer = Customer::factory()->create(['credit_limit' => null]);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

test('a MoMo reference already on a valid payment is refused with the existing receipt', function () {
    $first = recordPayment($this->owner, $this->customer, 5000, ['method' => 'momo', 'reference' => 'MP261002.1234.A1']);
    $other = Customer::factory()->create(['credit_limit' => null]);

    try {
        recordPayment($this->owner, $other, 5000, ['method' => 'momo', 'reference' => 'MP261002.1234.A1']);
        $this->fail('Expected duplicate_reference');
    } catch (DuplicatePaymentReferenceException $e) {
        expect($e->status())->toBe(409)
            ->and($e->errorCode())->toBe('duplicate_reference')
            ->and($e->details())->toMatchArray([
                'method' => 'momo',
                'reference' => 'MP261002.1234.A1',
                'existing_payment_id' => $first->id,
                'existing_receipt_no' => $first->receipt_no,
                'existing_customer_id' => $this->customer->id,
                'existing_amount' => 5000,
            ]);
    }

    expect(Payment::query()->count())->toBe(1)
        ->and($other->fresh()->credit_balance)->toBe(0);
});

test('matching ignores case and spaces in the reference', function () {
    recordPayment($this->owner, $this->customer, 100, ['method' => 'cheque', 'reference' => '000 123']);

    expect(fn () => recordPayment($this->owner, $this->customer, 100, ['method' => 'cheque', 'reference' => '000123']))
        ->toThrow(DuplicatePaymentReferenceException::class);

    recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => 'abc 9']);
    expect(fn () => recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => 'ABC9']))
        ->toThrow(DuplicatePaymentReferenceException::class);
});

test('the same reference is fine across methods and for cash', function () {
    recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => '777']);
    recordPayment($this->owner, $this->customer, 100, ['method' => 'cheque', 'reference' => '777']);
    recordPayment($this->owner, $this->customer, 100, ['method' => 'bank_transfer', 'reference' => '777']);
    recordPayment($this->owner, $this->customer, 100, ['method' => 'cash', 'reference' => 'Till 1']);
    recordPayment($this->owner, $this->customer, 100, ['method' => 'cash', 'reference' => 'Till 1']);

    expect(Payment::query()->count())->toBe(5)
        ->and(Payment::query()->whereNull('reference_key')->count())->toBe(2);
});

test('voiding a payment frees its reference for a corrected entry', function () {
    $wrong = recordPayment($this->owner, $this->customer, 900, ['method' => 'momo', 'reference' => 'MP-55']);
    app(VoidPayment::class)->execute($this->owner, $wrong, 'Wrong amount typed');

    expect($wrong->fresh()->reference_key)->toBeNull();

    $right = recordPayment($this->owner, $this->customer, 1900, ['method' => 'momo', 'reference' => 'MP-55']);

    expect($right->fresh()->reference_key)->toBe('momo:MP-55')
        ->and(Payment::query()->where('reference', 'MP-55')->count())->toBe(2);
});

test('the database itself refuses a duplicate even when the pre-check is bypassed', function () {
    recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => 'RACE-1']);

    $payment = new Payment([
        'receipt_no' => 'RCT-RAW-1', 'customer_id' => $this->customer->id, 'amount' => 100, 'method' => 'momo',
        'reference' => 'race-1', 'paid_at' => now(), 'status' => 'valid', 'received_by' => $this->owner->id,
    ]);

    expect(fn () => $payment->save())->toThrow(UniqueConstraintViolationException::class);
});

test('the reference key mirrors the database expression', function () {
    $payment = recordPayment($this->owner, $this->customer, 100, ['method' => 'bank_transfer', 'reference' => ' gcb 0042 x ']);

    expect($payment->fresh()->reference_key)
        ->toBe(Payment::referenceKey(PaymentMethod::BankTransfer, ' gcb 0042 x '))
        ->toBe('bank_transfer:GCB0042X');
});

test('API returns 409 duplicate_reference', function () {
    $first = recordPayment($this->owner, $this->customer, 300, ['method' => 'momo', 'reference' => 'MP-9']);

    $this->withToken($this->token)->withHeader('Idempotency-Key', 'dup')
        ->postJson('/api/v1/payments', ['customer_id' => $this->customer->id, 'amount' => 300, 'method' => 'momo', 'reference' => 'MP-9'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'duplicate_reference')
        ->assertJsonPath('details.existing_receipt_no', $first->receipt_no);
});

test('Filament shows a duplicate-reference notification', function () {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    recordPayment($this->owner, $this->customer, 300, ['method' => 'momo', 'reference' => 'MP-10']);

    Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
        ->callAction('recordPayment', data: [
            'amount' => '3.00', 'method' => 'momo', 'reference' => 'mp-10', 'paid_at' => '2026-10-02 09:00:00', 'allocation_mode' => 'credit',
        ])
        ->assertNotified('Payment already recorded');

    expect(Payment::query()->count())->toBe(1);
});
