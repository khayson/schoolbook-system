<?php

use App\Actions\Sales\ConfirmSale;
use App\Enums\PaymentRecordStatus;
use App\Filament\Resources\Payments\Pages\CreatePayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->customer = Customer::factory()->create(['credit_limit' => null]);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

function filamentInvoice(User $owner, Customer $customer, int $total, string $dueDate = '2026-10-20'): Sale
{
    return app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[stockedProduct(stock: 100, price: $total), 1]], $customer), ['due_date' => $dueDate]);
}

function paymentFormData(Customer $customer, array $overrides = []): array
{
    return [
        'customer_id' => $customer->id,
        'amount' => '19.99',
        'method' => 'cash',
        'reference' => null,
        'paid_at' => '2026-10-02 09:00:00',
        'notes' => null,
        'allocation_mode' => 'oldest',
        ...$overrides,
    ];
}

// --- Create -----------------------------------------------------------------------------------

test('recording a payment parses GHS exactly and allocates oldest first', function () {
    $older = filamentInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $newer = filamentInvoice($this->owner, $this->customer, 2000, '2026-10-25');

    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, ['amount' => '20.29']))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Payment RCT-2026-000001 recorded');

    $payment = Payment::query()->sole();

    expect($payment->amount)->toBe(2029)
        ->and($older->fresh()->balance_due)->toBe(0)
        ->and($newer->fresh()->balance_due)->toBe(971);
});

test('GHS parsing never goes through floats', function (string $typed, int $pesewas) {
    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, ['amount' => $typed, 'allocation_mode' => 'credit']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Payment::query()->sole()->amount)->toBe($pesewas);
})->with([
    ['19.99', 1999],
    ['0.29', 29],
    ['1.10', 110],
    ['1250', 125000],
]);

test('payment form validation', function (array $overrides, string $field) {
    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, $overrides))
        ->call('create')
        ->assertHasFormErrors([$field]);

    expect(Payment::query()->count())->toBe(0);
})->with([
    'three decimals' => [['amount' => '1.234'], 'amount'],
    'comma' => [['amount' => '1,000'], 'amount'],
    'negative' => [['amount' => '-5'], 'amount'],
    'blank' => [['amount' => ''], 'amount'],
    'over the cap' => [['amount' => '1000000000.01'], 'amount'],
    'momo needs reference' => [['method' => 'momo'], 'reference'],
    'cheque needs reference' => [['method' => 'cheque', 'reference' => ''], 'reference'],
    'future date' => [['paid_at' => '2026-10-03 09:00:00'], 'paid_at'],
]);

test('choosing invoices pays exactly those, keep-as-credit pays none', function () {
    Repeater::fake();
    $a = filamentInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $b = filamentInvoice($this->owner, $this->customer, 2000, '2026-10-25');

    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, [
            'amount' => '25.00',
            'allocation_mode' => 'choose',
            'allocations' => [['sale_id' => $b->id, 'amount' => '15.00']],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($a->fresh()->balance_due)->toBe(1000)
        ->and($b->fresh()->balance_due)->toBe(500)
        ->and($this->customer->fresh()->credit_balance)->toBe(1000);

    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, ['amount' => '5', 'allocation_mode' => 'credit']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($a->fresh()->balance_due)->toBe(1000)
        ->and($this->customer->fresh()->credit_balance)->toBe(1500);
});

test('a business error becomes a notification and the form stays', function () {
    Repeater::fake();
    $invoice = filamentInvoice($this->owner, $this->customer, 1000);

    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData($this->customer, [
            'amount' => '20.00',
            'allocation_mode' => 'choose',
            'allocations' => [['sale_id' => $invoice->id, 'amount' => '15.00']],
        ]))
        ->call('create')
        ->assertNotified('Amount is more than the invoice balance');

    expect(Payment::query()->count())->toBe(0);
});

test('customer_id in the query string preselects the customer', function () {
    $this->get(PaymentResource::getUrl('create', ['customer_id' => $this->customer->id]))->assertOk();

    Livewire::withQueryParams(['customer_id' => $this->customer->id])
        ->test(CreatePayment::class)
        ->assertSchemaStateSet(['customer_id' => $this->customer->id]);
});

// --- View, void, receipt ------------------------------------------------------------------------

test('void payment action reverses it and then disappears', function () {
    filamentInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 1000);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertActionVisible('void')
        ->callAction('void', data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($payment->fresh()->isValid())->toBeTrue();

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('void', data: ['reason' => 'Bounced'])
        ->assertHasNoFormErrors()
        ->assertNotified("Payment {$payment->receipt_no} voided")
        ->assertActionHidden('void')
        ->assertActionHidden('downloadReceipt');

    expect($payment->fresh()->status)->toBe(PaymentRecordStatus::Void);
});

test('download receipt streams the PDF', function () {
    $payment = recordPayment($this->owner, $this->customer, 500);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('downloadReceipt')
        ->assertFileDownloaded("{$payment->receipt_no}.pdf");
});

test('payments can be filtered and have no edit or delete', function () {
    $momo = recordPayment($this->owner, $this->customer, 100, ['method' => 'momo', 'reference' => 'R1']);
    $cash = recordPayment($this->owner, $this->customer, 200);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$momo, $cash])
        ->filterTable('method', 'momo')
        ->assertCanSeeTableRecords([$momo])
        ->assertCanNotSeeTableRecords([$cash])
        ->assertActionDoesNotExist(TestAction::make('edit')->table($momo))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($momo));

    expect(PaymentResource::canEdit($momo))->toBeFalse()
        ->and(PaymentResource::canDelete($momo))->toBeFalse()
        ->and(PaymentResource::hasPage('edit'))->toBeFalse();
});
