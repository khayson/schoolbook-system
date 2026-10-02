<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\MarkDelivered;
use App\Enums\SaleStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceChangedException;
use App\Exceptions\SaleDeliveredException;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Sales\Pages\CreateSale;
use App\Filament\Resources\Sales\Pages\EditSale;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Support\DomainErrorNotifier;
use App\Models\Customer;
use App\Models\Level;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\PricingService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    Setting::setValue('default_payment_terms_days', 30);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->customer = Customer::factory()->create(['credit_limit' => null]);
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

function viewSale(Sale $sale)
{
    return Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()]);
}

// --- Draft create / edit -------------------------------------------------------------------------

test('creating a draft goes through CreateDraftSale, with an override priced exactly', function () {
    Repeater::fake();
    $reader = stockedProduct(stock: 50, price: 2500);
    $maths = stockedProduct(stock: 50, price: 4000);

    Livewire::test(CreateSale::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'sale_date' => '2026-10-02',
            'items' => [
                ['product_id' => $reader->id, 'quantity' => 10],
                ['product_id' => $maths->id, 'quantity' => 2, 'override_unit_price' => '35.50', 'override_reason' => 'Bulk order'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $sale = Sale::query()->sole();
    $items = $sale->items()->orderBy('id')->get();

    expect($sale->status)->toBe(SaleStatus::Draft)
        ->and($sale->total)->toBe(10 * 2500 + 2 * 3550)
        ->and($items[1]->unit_price)->toBe(3550)
        ->and($items[1]->is_price_overridden)->toBeTrue()
        ->and($items[1]->override_reason)->toBe('Bulk order');
});

test('draft form validation: override needs a reason, quantity at least 1', function () {
    Repeater::fake();
    $product = stockedProduct(stock: 5);

    Livewire::test(CreateSale::class)
        ->fillForm([
            'customer_id' => $this->customer->id,
            'sale_date' => '2026-10-02',
            'items' => [['product_id' => $product->id, 'quantity' => 0, 'override_unit_price' => '9.99']],
        ])
        ->call('create')
        ->assertHasFormErrors(['items.0.quantity', 'items.0.override_reason']);

    expect(Sale::query()->count())->toBe(0);
});

test('the product picker narrows by level, subject and language and hides inactive books', function () {
    $a = stockedProduct(stock: 5, attributes: ['title' => 'Alpha Reader']);
    $otherLevel = Level::query()->whereKeyNot($a->level_id)->value('id')
        ?? Level::query()->create(['level_group_id' => Level::query()->value('level_group_id'), 'name' => 'Other', 'slug' => 'other', 'sort_order' => 99])->id;
    $b = stockedProduct(stock: 5, attributes: ['title' => 'Beta Maths', 'level_id' => $otherLevel]);
    $inactive = stockedProduct(stock: 5, attributes: ['level_id' => $a->level_id, 'is_active' => false]);

    $byLevel = SaleForm::productOptions($a->level_id);
    $all = SaleForm::productOptions();

    expect($byLevel)->toHaveKey($a->id)
        ->and($byLevel)->not->toHaveKey($b->id)
        ->and($all)->toHaveKeys([$a->id, $b->id])
        ->and($all)->not->toHaveKey($inactive->id)
        ->and($byLevel[$a->id])->toContain('Alpha Reader')->toContain('stock 5');
});

test('editing a draft goes through UpdateDraftSale and is only possible for drafts', function () {
    Repeater::fake();
    $product = stockedProduct(stock: 20, price: 1000);
    $sale = makeDraftSale($this->owner, [[$product, 2, 800, 'Loyal school']], $this->customer);

    Livewire::test(EditSale::class, ['record' => $sale->getRouteKey()])
        ->assertSchemaStateSet([
            'items.0.product_id' => $product->id,
            'items.0.quantity' => 2,
            'items.0.override_unit_price' => '8.00',
            'items.0.override_reason' => 'Loyal school',
        ])
        ->fillForm(['items' => [['product_id' => $product->id, 'quantity' => 5, 'override_unit_price' => null, 'override_reason' => null]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($sale->fresh()->total)->toBe(5000);

    app(ConfirmSale::class)->execute($this->owner, $sale->fresh());
    expect(SaleResource::canEdit($sale->fresh()))->toBeFalse();
    $this->get(SaleResource::getUrl('edit', ['record' => $sale]))->assertForbidden();
});

// --- Confirm and its exception notifications -------------------------------------------------------

test('confirm issues the invoice', function () {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1000), 3]], $this->customer);

    viewSale($sale)
        ->assertActionVisible('confirm')
        ->callAction('confirm', data: ['due_date' => '2026-11-15'])
        ->assertHasNoFormErrors()
        ->assertNotified('Invoice INV-2026-000001 issued')
        ->assertActionHidden('confirm')
        ->assertActionVisible('downloadInvoice');

    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->fresh()->due_date->toDateString())->toBe('2026-11-15');
});

test('price_changed shows the changed lines, writes nothing, and re-price then confirm works', function () {
    $product = stockedProduct(stock: 10, price: 1000, attributes: ['title' => 'English Reader P4']);
    $sale = makeDraftSale($this->owner, [[$product, 3]], $this->customer);
    $product->update(['selling_price' => 1200]);

    viewSale($sale)
        ->callAction('confirm')
        ->assertNotified('Prices changed');

    expect($sale->fresh()->status)->toBe(SaleStatus::Draft);

    [$title, $lines] = DomainErrorNotifier::describe(
        new PriceChangedException(app(PricingService::class)->priceLines($this->customer, [['product_id' => $product->id, 'quantity' => 3]], now())),
        $sale->fresh(),
    );
    expect($lines)->toContain('English Reader P4: GHS 10.00 -> GHS 12.00')
        ->and($lines)->toContain('Total: GHS 30.00 -> GHS 36.00');

    viewSale($sale)
        ->callAction('reprice')
        ->assertNotified('Draft re-priced');

    viewSale($sale->fresh())
        ->callAction('confirm')
        ->assertNotified('Invoice INV-2026-000001 issued');

    expect($sale->fresh()->total)->toBe(3600);
});

test('insufficient_stock lists every short book', function () {
    $short = stockedProduct(stock: 1, attributes: ['sku' => 'ENG-P4', 'title' => 'English P4']);
    $sale = makeDraftSale($this->owner, [[$short, 5]], $this->customer);

    viewSale($sale)
        ->callAction('confirm')
        ->assertNotified('Not enough stock');

    [, $lines] = DomainErrorNotifier::describe(new InsufficientStockException([
        ['product_id' => $short->id, 'sku' => 'ENG-P4', 'title' => 'English P4', 'requested' => 5, 'available' => 1],
    ]));
    expect($lines[0])->toBe('ENG-P4 English P4: need 5, have 1')
        ->and($sale->fresh()->status)->toBe(SaleStatus::Draft);
});

test('credit_limit_exceeded warns, then confirming with the override succeeds', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1500), 1]], $customer);

    viewSale($sale)
        ->callAction('confirm')
        ->assertNotified('Over credit limit');

    expect($sale->fresh()->status)->toBe(SaleStatus::Draft);

    viewSale($sale)
        ->callAction('confirm', data: ['override_credit_limit' => true])
        ->assertNotified('Invoice INV-2026-000001 issued');
});

test('confirm can apply customer credit', function () {
    recordPayment($this->owner, $this->customer, 700);
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1000), 1]], $this->customer);

    viewSale($sale)
        ->callAction('confirm', data: ['apply_credit' => true])
        ->assertNotified('Invoice INV-2026-000001 issued');

    expect($sale->fresh()->balance_due)->toBe(300)
        ->and($this->customer->fresh()->credit_balance)->toBe(0);
});

// --- Cancel, void, deliver, invoice ---------------------------------------------------------------

test('cancel a draft', function () {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $this->customer);

    viewSale($sale)
        ->callAction('cancel', data: ['reason' => 'School changed its mind'])
        ->assertNotified('Draft cancelled')
        ->assertActionHidden('confirm');

    expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
        ->and($sale->fresh()->cancel_reason)->toBe('School changed its mind');
});

test('void needs a reason, reverses stock and payments', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 2]], $this->customer));
    recordPayment($this->owner, $this->customer, 500);

    viewSale($sale)
        ->callAction('void', data: ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    viewSale($sale)
        ->callAction('void', data: ['reason' => 'Wrong school'])
        ->assertNotified("Invoice {$sale->invoice_no} voided")
        ->assertActionHidden('void');

    expect($sale->fresh()->status)->toBe(SaleStatus::Void)
        ->and($product->fresh()->stock_on_hand)->toBe(10)
        ->and($this->customer->fresh()->credit_balance)->toBe(500);
});

test('mark delivered hides void; a delivered sale cannot be voided', function () {
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $this->customer));

    viewSale($sale)
        ->callAction('deliver')
        ->assertNotified('Marked as delivered')
        ->assertActionHidden('void')
        ->assertActionHidden('deliver');

    expect($sale->fresh()->delivered_at)->not->toBeNull();
});

test('an action made invalid by a concurrent change does not run', function () {
    $product = stockedProduct(stock: 10);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]], $this->customer));
    $page = viewSale($sale);

    // Someone else delivers it after this page loaded; Livewire reloads the record on the
    // next request, so Void is no longer offered and cannot be called.
    app(MarkDelivered::class)->execute($this->owner, $sale);

    $page->callAction('void', data: ['reason' => 'Too late']);

    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed)
        ->and($product->fresh()->stock_on_hand)->toBe(9);
});

test('unmapped business errors fall back to a readable title and the message', function () {
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $this->customer));
    app(MarkDelivered::class)->execute($this->owner, $sale);

    [$title, $lines] = DomainErrorNotifier::describe(new SaleDeliveredException($sale->fresh()));

    expect($title)->toBe('Sale Delivered')
        ->and($lines[0])->toContain('cannot be voided');
});

test('download invoice streams the PDF; drafts have no invoice button', function () {
    $draft = makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $this->customer);
    viewSale($draft)->assertActionHidden('downloadInvoice');

    $sale = app(ConfirmSale::class)->execute($this->owner, $draft);

    viewSale($sale)
        ->callAction('downloadInvoice')
        ->assertFileDownloaded("{$sale->invoice_no}.pdf");
});

test('record payment shortcut links to the payment form for this customer', function () {
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $this->customer));

    viewSale($sale)->assertActionHasUrl('recordPayment', PaymentResource::getUrl('create', ['customer_id' => $this->customer->id]));
});

// --- List filters ----------------------------------------------------------------------------------

test('sales list filters by status, payment status, customer and overdue', function () {
    $product = stockedProduct(stock: 100, price: 1000);
    $draft = makeDraftSale($this->owner, [[$product, 1]], $this->customer);
    $overdue = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]], $this->customer), ['due_date' => '2026-09-30']);
    $current = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]], $this->customer), ['due_date' => '2026-10-30']);
    $other = Customer::factory()->create(['credit_limit' => null]);
    $paid = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]], $other), ['due_date' => '2026-09-01']);
    recordPayment($this->owner, $other, 1000);

    Livewire::test(ListSales::class)
        ->assertCanSeeTableRecords([$draft, $overdue, $current, $paid])
        ->filterTable('status', 'draft')->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$overdue])
        ->resetTableFilters()
        ->filterTable('payment_status', 'paid')->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$current])
        ->resetTableFilters()
        ->filterTable('customer_id', $other->id)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters()
        ->filterTable('overdue')->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$current, $paid, $draft]);
});
