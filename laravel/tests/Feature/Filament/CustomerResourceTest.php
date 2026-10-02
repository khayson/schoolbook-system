<?php

use App\Actions\Sales\ConfirmSale;
use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\SalesRelationManager;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sales\SaleResource;
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
    $this->customer = Customer::factory()->create(['credit_limit' => null, 'name' => 'Akwaaba Basic School']);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

function customerInvoice(User $owner, Customer $customer, int $total, string $dueDate = '2026-10-20'): Sale
{
    return app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, [[stockedProduct(stock: 100, price: $total), 1]], $customer), ['due_date' => $dueDate]);
}

function editCustomer(Customer $customer)
{
    return Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()]);
}

// --- Create / edit -------------------------------------------------------------------------------

test('creating a customer stores the credit limit in exact pesewas and a CUS code', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'name' => 'Bethel Academy',
            'type' => CustomerType::School->value,
            'region' => GhanaRegion::BonoEast->value,
            'district' => 'Techiman',
            'contact_person' => 'Mrs Owusu',
            'phone' => '0244000001',
            'credit_limit' => '2500.29',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::query()->where('name', 'Bethel Academy')->sole();

    expect($customer->credit_limit)->toBe(250029)
        ->and($customer->region)->toBe(GhanaRegion::BonoEast)
        ->and($customer->code)->toStartWith('CUS-');
});

test('credit limit input rejects floats with more than 2 decimals; blank means no limit', function () {
    editCustomer($this->customer)
        ->fillForm(['credit_limit' => '10.999'])
        ->call('save')
        ->assertHasFormErrors(['credit_limit']);

    editCustomer($this->customer)
        ->assertSchemaStateSet(['credit_limit' => null])
        ->fillForm(['credit_limit' => '1500'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->customer->fresh()->credit_limit)->toBe(150000);

    editCustomer($this->customer->fresh())
        ->assertSchemaStateSet(['credit_limit' => '1500.00'])
        ->fillForm(['credit_limit' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->customer->fresh()->credit_limit)->toBeNull();
});

test('the region list is the 16 regions of Ghana', function () {
    expect(GhanaRegion::cases())->toHaveCount(16);
});

// --- Money actions on the customer ----------------------------------------------------------------

test('record payment from the customer page allocates oldest first', function () {
    $old = customerInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $new = customerInvoice($this->owner, $this->customer, 1000, '2026-10-25');

    editCustomer($this->customer)
        ->callAction('recordPayment', data: [
            'amount' => '15.00',
            'method' => 'momo',
            'reference' => 'MP261002.9',
            'paid_at' => '2026-10-02 09:30:00',
            'allocation_mode' => 'oldest',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Payment RCT-2026-000001 recorded');

    expect($old->fresh()->balance_due)->toBe(0)
        ->and($new->fresh()->balance_due)->toBe(500)
        ->and(Payment::query()->sole()->reference)->toBe('MP261002.9');
});

test('record payment validates the reference for non-cash', function () {
    editCustomer($this->customer)
        ->callAction('recordPayment', data: ['amount' => '10', 'method' => 'bank_transfer', 'reference' => '', 'allocation_mode' => 'credit'])
        ->assertHasFormErrors(['reference' => 'required']);

    expect(Payment::query()->count())->toBe(0);
});

test('apply credit: oldest first, chosen invoices, and the over-credit error notification', function () {
    Repeater::fake();
    $a = customerInvoice($this->owner, $this->customer, 1000, '2026-10-05');
    $b = customerInvoice($this->owner, $this->customer, 1000, '2026-10-25');

    editCustomer($this->customer)->assertActionHidden('applyCredit');

    recordPayment($this->owner, $this->customer, 1200, ['auto_allocate' => false]);

    editCustomer($this->customer->fresh())
        ->callAction('applyCredit', data: ['mode' => 'choose', 'allocations' => [
            ['sale_id' => $a->id, 'amount' => '10.00'],
            ['sale_id' => $b->id, 'amount' => '3.00'],
        ]])
        ->assertNotified('Not enough credit');

    expect($a->fresh()->balance_due)->toBe(1000)
        ->and($b->fresh()->balance_due)->toBe(1000);

    editCustomer($this->customer->fresh())
        ->callAction('applyCredit', data: ['mode' => 'choose', 'allocations' => [['sale_id' => $b->id, 'amount' => '3.00']]])
        ->assertNotified('Credit applied: GHS 3.00');

    editCustomer($this->customer->fresh())
        ->callAction('applyCredit', data: ['mode' => 'oldest'])
        ->assertNotified('Credit applied: GHS 9.00');

    expect($a->fresh()->balance_due)->toBe(100)
        ->and($b->fresh()->balance_due)->toBe(700)
        ->and($this->customer->fresh()->credit_balance)->toBe(0);
});

test('deactivate and reactivate; there is no delete anywhere', function () {
    editCustomer($this->customer)
        ->assertActionDoesNotExist('delete')
        ->callAction('deactivate')
        ->assertNotified('Akwaaba Basic School deactivated')
        ->assertActionHidden('deactivate')
        ->assertActionVisible('reactivate');

    expect($this->customer->fresh()->is_active)->toBeFalse();

    editCustomer($this->customer->fresh())->callAction('reactivate');
    expect($this->customer->fresh()->is_active)->toBeTrue()
        ->and(CustomerResource::canDelete($this->customer))->toBeFalse()
        ->and(CustomerResource::canDeleteAny())->toBeFalse();

    Livewire::test(ListCustomers::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($this->customer));
});

// --- Lists and relation managers --------------------------------------------------------------------

test('customer list shows balances and filters by owes / has credit', function () {
    customerInvoice($this->owner, $this->customer, 1000);
    $creditor = Customer::factory()->create(['credit_limit' => null]);
    recordPayment($this->owner, $creditor, 500);

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$this->customer, $creditor])
        ->assertTableColumnFormattedStateSet('outstanding_balance', 'GHS 10.00', $this->customer)
        ->assertTableColumnFormattedStateSet('credit_balance', 'GHS 5.00', $creditor)
        ->filterTable('owes')
        ->assertCanSeeTableRecords([$this->customer])
        ->assertCanNotSeeTableRecords([$creditor]);

    Livewire::test(ListCustomers::class)
        ->filterTable('has_credit')
        ->assertCanSeeTableRecords([$creditor])
        ->assertCanNotSeeTableRecords([$this->customer]);
});

test('relation managers list the customer\'s sales and payments read-only', function () {
    $sale = customerInvoice($this->owner, $this->customer, 1000);
    $payment = recordPayment($this->owner, $this->customer, 400);

    Livewire::test(SalesRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => EditCustomer::class])
        ->assertCanSeeTableRecords([$sale])
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('delete')->table($sale));

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->customer, 'pageClass' => EditCustomer::class])
        ->assertCanSeeTableRecords([$payment])
        ->assertActionDoesNotExist(TestAction::make('create')->table());
});

test('school users cannot reach the admin resources', function () {
    $school = User::factory()->school()->create();
    $this->actingAs($school);

    $this->get(CustomerResource::getUrl('index'))->assertForbidden();
    $this->get(PaymentResource::getUrl('index'))->assertForbidden();
    $this->get(SaleResource::getUrl('index'))->assertForbidden();
});
