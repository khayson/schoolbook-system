<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\VoidSale;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    Setting::setValue('default_payment_terms_days', 30);

    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

afterEach(fn () => Carbon::setTestNow());

function confirmViaApi($test, Sale $sale, array $body = [], string $key = 'confirm-key-1')
{
    return $test->withToken($test->token)
        ->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/sales/{$sale->id}/confirm", $body);
}

function assertNothingWritten(Sale $sale): void
{
    $fresh = $sale->fresh();

    expect($fresh->status)->toBe(SaleStatus::Draft)
        ->and($fresh->invoice_no)->toBeNull()
        ->and($fresh->balance_due)->toBe(0)
        ->and(StockMovement::query()->where('reference_type', 'sale')->count())->toBe(0)
        ->and((int) NumberSequence::query()->where('key', 'inv')->value('last_number'))->toBe(0);
}

// --- Happy path ------------------------------------------------------------------

test('confirm writes sale_out movements, deducts stock and issues the invoice', function () {
    $english = stockedProduct(stock: 50, price: 2500, cost: 1500);
    $maths = stockedProduct(stock: 20, price: 4000, cost: 3000);
    $sale = makeDraftSale($this->owner, [[$english, 10], [$maths, 5]], extra: ['sale_date' => '2026-09-28']);

    $response = confirmViaApi($this, $sale)->assertOk();

    $response->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.invoice_no', 'INV-2026-000001')
        ->assertJsonPath('data.total', 45000)
        ->assertJsonPath('data.balance_due', 45000)
        ->assertJsonPath('data.amount_paid', 0)
        ->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.due_date', '2026-10-31')
        ->assertJsonPath('data.confirmed_by', $this->owner->id);

    expect($english->fresh()->stock_on_hand)->toBe(40)
        ->and($maths->fresh()->stock_on_hand)->toBe(15);

    $movements = StockMovement::query()->where('reference_type', 'sale')->where('reference_id', $sale->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('type')->all())->toBe([StockMovementType::SaleOut, StockMovementType::SaleOut])
        ->and($movements->pluck('quantity')->all())->toBe([-10, -5])
        ->and($movements->pluck('balance_after')->all())->toBe([40, 15])
        ->and($movements->pluck('unit_cost')->all())->toBe([1500, 3000])
        ->and($movements->pluck('user_id')->unique()->all())->toBe([$this->owner->id]);

    $status = Activity::query()
        ->where('subject_type', 'sale')->where('subject_id', $sale->id)
        ->where('event', 'updated')->latest('id')->first();

    expect($status->causer_id)->toBe($this->owner->id)
        ->and($status->attribute_changes['old']['status'])->toBe('draft')
        ->and($status->attribute_changes['attributes']['status'])->toBe('confirmed')
        ->and($status->attribute_changes['attributes']['invoice_no'])->toBe('INV-2026-000001');
});

test('one product on several lines writes a running balance per line', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = makeDraftSale($this->owner, [[$product, 3], [$product, 4, 800, 'Bulk']]);

    app(ConfirmSale::class)->execute($this->owner, $sale);

    expect(StockMovement::query()->orderBy('id')->pluck('balance_after')->all())->toBe([7, 3])
        ->and($product->fresh()->stock_on_hand)->toBe(3);
});

test('invoice numbers are sequential across confirmations', function () {
    $product = stockedProduct(stock: 100);
    $numbers = collect(range(1, 3))->map(function () use ($product) {
        return app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]]))->invoice_no;
    });

    expect($numbers->all())->toBe(['INV-2026-000001', 'INV-2026-000002', 'INV-2026-000003']);
});

test('invoice year comes from the confirmation date, not the sale date', function () {
    $product = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$product, 1]], extra: ['sale_date' => '2025-12-31']);

    Carbon::setTestNow('2026-01-02 09:00:00');
    $confirmed = app(ConfirmSale::class)->execute($this->owner, $sale);

    expect($confirmed->invoice_no)->toBe('INV-2026-000001')
        ->and($confirmed->sale_date->toDateString())->toBe('2025-12-31')
        ->and(NumberSequence::query()->where('key', 'inv')->where('year', 2025)->exists())->toBeFalse();
});

test('due date comes from the request, then the draft, then payment terms', function () {
    $product = stockedProduct(stock: 10);
    Setting::setValue('default_payment_terms_days', 14);

    $fromTerms = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]]));
    $fromDraft = app(ConfirmSale::class)->execute(
        $this->owner,
        makeDraftSale($this->owner, [[$product, 1]], extra: ['due_date' => '2026-11-20']),
    );
    $fromRequest = app(ConfirmSale::class)->execute(
        $this->owner,
        makeDraftSale($this->owner, [[$product, 1]], extra: ['due_date' => '2026-11-20']),
        ['due_date' => '2026-12-05'],
    );

    expect($fromTerms->due_date->toDateString())->toBe('2026-10-15')
        ->and($fromDraft->due_date->toDateString())->toBe('2026-11-20')
        ->and($fromRequest->due_date->toDateString())->toBe('2026-12-05');
});

test('unit cost is refreshed from the locked product at confirmation', function () {
    $product = stockedProduct(stock: 10, price: 1000, cost: 500);
    $sale = makeDraftSale($this->owner, [[$product, 2]]);

    $product->update(['cost_price' => 650]);

    $confirmed = app(ConfirmSale::class)->execute($this->owner, $sale);

    expect($confirmed->items->first()->unit_cost)->toBe(650)
        ->and(StockMovement::query()->first()->unit_cost)->toBe(650);
});

// --- Idempotency ------------------------------------------------------------------------

test('replaying the same Idempotency-Key returns the same invoice without a second movement', function () {
    $product = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$product, 2]]);

    $first = confirmViaApi($this, $sale, ['override_credit_limit' => false], 'same-key')->assertOk();
    $second = confirmViaApi($this, $sale, ['override_credit_limit' => false], 'same-key')->assertOk();

    $second->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('data.invoice_no'))->toBe($first->json('data.invoice_no'))
        ->and(StockMovement::query()->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(8);
});

test('confirm requires an Idempotency-Key', function () {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/confirm")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'idempotency_key_required');

    assertNothingWritten($sale);
});

test('confirming an already confirmed sale with a new key returns 409', function () {
    $product = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$product, 2]]);

    confirmViaApi($this, $sale, key: 'k1')->assertOk();
    confirmViaApi($this, $sale, key: 'k2')
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_not_editable')
        ->assertJsonPath('details.action', 'confirm');

    expect(StockMovement::query()->count())->toBe(1);
});

// --- Price changed ------------------------------------------------------------------------

test('a changed price returns 409 price_changed with the new priced order and writes nothing', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = makeDraftSale($this->owner, [[$product, 3]]);

    $product->update(['selling_price' => 1200]);

    confirmViaApi($this, $sale)
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed')
        ->assertJsonPath('details.priced_order.total', 3600)
        ->assertJsonPath('details.priced_order.lines.0.unit_price', 1200);

    assertNothingWritten($sale);
    expect($product->fresh()->stock_on_hand)->toBe(10)
        ->and($sale->fresh()->total)->toBe(3000);
});

test('after price_changed, re-saving the draft accepts the new prices and confirm succeeds', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = makeDraftSale($this->owner, [[$product, 3]]);
    $product->update(['selling_price' => 1200]);

    confirmViaApi($this, $sale, key: 'retry-key')->assertStatus(409);

    $this->withToken($this->token)->putJson("/api/v1/sales/{$sale->id}", [])
        ->assertOk()
        ->assertJsonPath('data.total', 3600);

    // Same key is reusable: the 409 released the claim.
    confirmViaApi($this, $sale, key: 'retry-key')
        ->assertOk()
        ->assertJsonPath('data.total', 3600)
        ->assertJsonPath('data.balance_due', 3600);
});

test('a base price change under an unchanged override is not a price change', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = makeDraftSale($this->owner, [[$product, 2, 700, 'Loyal school']]);

    $product->update(['selling_price' => 1100]);

    $confirmed = app(ConfirmSale::class)->execute($this->owner, $sale);
    $item = $confirmed->items->first();

    expect($confirmed->total)->toBe(1400)
        ->and($item->unit_price)->toBe(700)
        ->and($item->base_price)->toBe(1100)
        ->and($item->discount_amount)->toBe(800)
        ->and($item->is_price_overridden)->toBeTrue();
});

// --- Inactive / deleted after drafting --------------------------------------------------------

test('a product deactivated after drafting is rejected on its line', function () {
    $ok = stockedProduct(stock: 10);
    $gone = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$ok, 1], [$gone, 1]]);

    $gone->update(['is_active' => false]);

    confirmViaApi($this, $sale)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('items.1.product_id');

    assertNothingWritten($sale);
});

test('a product soft-deleted after drafting is rejected', function () {
    $product = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$product, 1]]);

    $product->delete();

    expect(fn () => app(ConfirmSale::class)->execute($this->owner, $sale))
        ->toThrow(InvalidInputException::class);

    assertNothingWritten($sale);
});

test('a customer deactivated after drafting is rejected', function () {
    $customer = Customer::factory()->create();
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10), 1]], $customer);

    $customer->update(['is_active' => false]);

    confirmViaApi($this, $sale)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');
});

// --- Stock ----------------------------------------------------------------------------------

test('insufficient stock lists every short product and writes nothing', function () {
    $short1 = stockedProduct(stock: 2, attributes: ['sku' => 'SHORT-1']);
    $fine = stockedProduct(stock: 50);
    $short2 = stockedProduct(stock: 0, attributes: ['sku' => 'SHORT-2']);
    $sale = makeDraftSale($this->owner, [[$short1, 5], [$fine, 5], [$short2, 1]]);

    $response = confirmViaApi($this, $sale)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_stock')
        ->assertJsonCount(2, 'details.items');

    expect($response->json('details.items'))->toBe([
        ['product_id' => $short1->id, 'sku' => 'SHORT-1', 'title' => $short1->title, 'requested' => 5, 'available' => 2],
        ['product_id' => $short2->id, 'sku' => 'SHORT-2', 'title' => $short2->title, 'requested' => 1, 'available' => 0],
    ]);

    assertNothingWritten($sale);
    expect($fine->fresh()->stock_on_hand)->toBe(50);
});

test('duplicate lines for one product are checked against stock as a sum', function () {
    $product = stockedProduct(stock: 5, price: 1000);
    // Different override prices keep these as two lines; together they need 6.
    $sale = makeDraftSale($this->owner, [[$product, 3], [$product, 3, 900, 'Promo']]);

    expect($sale->items)->toHaveCount(2);

    confirmViaApi($this, $sale)
        ->assertUnprocessable()
        ->assertJsonPath('details.items.0.requested', 6)
        ->assertJsonPath('details.items.0.available', 5);

    assertNothingWritten($sale);
});

test('allow_negative_stock lets a short confirmation through', function () {
    Setting::setValue('allow_negative_stock', true);
    $product = stockedProduct(stock: 1);
    $sale = makeDraftSale($this->owner, [[$product, 4]]);

    confirmViaApi($this, $sale)->assertOk();

    expect($product->fresh()->stock_on_hand)->toBe(-3)
        ->and(StockMovement::query()->first()->balance_after)->toBe(-3);
});

// --- Credit limit -------------------------------------------------------------------------------

test('exceeding the credit limit returns a 409 warning and writes nothing', function () {
    $customer = Customer::factory()->create(['credit_limit' => 10000]);
    $product = stockedProduct(stock: 100, price: 1000);

    app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 8]], $customer));
    $sale = makeDraftSale($this->owner, [[$product, 5]], $customer);

    confirmViaApi($this, $sale)
        ->assertStatus(409)
        ->assertJsonPath('code', 'credit_limit_exceeded')
        ->assertJsonPath('details.credit_limit', 10000)
        ->assertJsonPath('details.outstanding', 8000)
        ->assertJsonPath('details.sale_total', 5000)
        ->assertJsonPath('details.projected_balance', 13000)
        ->assertJsonPath('details.override_flag', 'override_credit_limit');

    expect($sale->fresh()->status)->toBe(SaleStatus::Draft)
        ->and($product->fresh()->stock_on_hand)->toBe(92);
});

test('override_credit_limit confirms and logs the override', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 10, price: 1000), 2]], $customer);

    confirmViaApi($this, $sale, ['override_credit_limit' => true])->assertOk();

    $log = Activity::query()->where('event', 'credit_limit_overridden')->first();

    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed)
        ->and($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->owner->id)
        ->and($log->subject_id)->toBe($sale->id)
        ->and($log->properties['projected_balance'])->toBe(2000);
});

test('credit limit boundaries: exactly at the limit passes, no limit never warns', function () {
    $atLimit = Customer::factory()->create(['credit_limit' => 2000]);
    $noLimit = Customer::factory()->create(['credit_limit' => null]);
    $product = stockedProduct(stock: 1000, price: 1000);

    $a = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 2]], $atLimit));
    $b = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 900]], $noLimit));

    expect($a->status)->toBe(SaleStatus::Confirmed)
        ->and($b->status)->toBe(SaleStatus::Confirmed)
        ->and(Activity::query()->where('event', 'credit_limit_overridden')->count())->toBe(0);
});

test('void and draft sales do not count towards outstanding', function () {
    $customer = Customer::factory()->create(['credit_limit' => 5000]);
    $product = stockedProduct(stock: 100, price: 1000);

    makeDraftSale($this->owner, [[$product, 4]], $customer);
    $voided = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 4]], $customer));
    app(VoidSale::class)->execute($this->owner, $voided, 'Wrong school');

    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 5]], $customer));

    expect($sale->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->payment_status)->toBe(PaymentStatus::Unpaid);
});
