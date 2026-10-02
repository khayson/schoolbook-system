<?php

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\MarkDelivered;
use App\Actions\Sales\UpdateDraftSale;
use App\Actions\Sales\VoidSale;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleNotEditableException;
use App\Exceptions\SaleStateConflictException;
use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    Setting::setValue('allow_negative_stock', false);

    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

afterEach(function () {
    // Every scenario must leave the money caches consistent with the ledger.
    assertMoneyInvariants();
    Carbon::setTestNow();
});

function confirmedSale(User $owner, array $lines, ?Customer $customer = null): Sale
{
    return app(ConfirmSale::class)->execute($owner, makeDraftSale($owner, $lines, $customer));
}

// --- State machine ---------------------------------------------------------------------

test('sale status transitions follow the spec state machine', function () {
    $allowed = [
        'draft' => ['confirmed', 'cancelled'],
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['void'],
        'cancelled' => [],
        'void' => [],
    ];

    foreach (SaleStatus::cases() as $from) {
        foreach (SaleStatus::cases() as $to) {
            expect($from->canTransitionTo($to))
                ->toBe(in_array($to->value, $allowed[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

test('illegal moves fail through the transition guard', function (string $from, string $actionClass, array $args) {
    // A zero-total confirmed sale is fully paid by definition (PaymentStatus::derive).
    $sale = Sale::factory()->create([
        'status' => $from,
        'payment_status' => $from === 'confirmed' ? 'paid' : 'unpaid',
        'created_by' => $this->owner->id,
    ]);

    expect(fn () => app($actionClass)->execute($this->owner, $sale, ...$args))
        ->toThrow(SaleNotEditableException::class);

    expect($sale->fresh()->status->value)->toBe($from);
})->with([
    'void -> confirmed' => ['void', ConfirmSale::class, []],
    'cancelled -> confirmed' => ['cancelled', ConfirmSale::class, []],
    'confirmed -> cancelled' => ['confirmed', CancelSale::class, []],
    'void -> cancelled' => ['void', CancelSale::class, []],
    'draft -> void' => ['draft', VoidSale::class, ['reason']],
    'cancelled -> void' => ['cancelled', VoidSale::class, ['reason']],
    'void -> void' => ['void', VoidSale::class, ['reason']],
]);

// --- Cancel ------------------------------------------------------------------------------

test('cancel moves a draft to cancelled with an optional reason and no stock effect', function () {
    $product = stockedProduct(stock: 10);
    $sale = makeDraftSale($this->owner, [[$product, 3]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'School changed order'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'School changed order')
        ->assertJsonPath('data.invoice_no', null);

    $withoutReason = app(CancelSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 1]]));

    expect($withoutReason->cancel_reason)->toBeNull()
        ->and($withoutReason->cancelled_at)->not->toBeNull()
        ->and($product->fresh()->stock_on_hand)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(0);
});

test('cancelling a confirmed sale via api returns 409', function () {
    $sale = confirmedSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_not_editable')
        ->assertJsonPath('details.action', 'cancel');
});

// --- Void ---------------------------------------------------------------------------------

test('void restores stock exactly with sale_void_in movements at the snapshot cost', function () {
    $a = stockedProduct(stock: 20, price: 1000, cost: 600);
    $b = stockedProduct(stock: 8, price: 2000, cost: 1500);
    $sale = confirmedSale($this->owner, [[$a, 5], [$b, 3], [$a, 2, 900, 'Promo']]);

    $a->update(['cost_price' => 999]); // later cost change must not affect the reversal

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Duplicate invoice'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.balance_due', 0)
        ->assertJsonPath('data.invoice_no', $sale->invoice_no)
        ->assertJsonPath('data.void_reason', 'Duplicate invoice')
        ->assertJsonPath('data.voided_by', $this->owner->id);

    expect($a->fresh()->stock_on_hand)->toBe(20)
        ->and($b->fresh()->stock_on_hand)->toBe(8);

    $reversals = StockMovement::query()->where('type', StockMovementType::SaleVoidIn)->orderBy('id')->get();
    expect($reversals)->toHaveCount(3)
        ->and($reversals->pluck('quantity')->all())->toBe([5, 3, 2])
        ->and($reversals->pluck('unit_cost')->all())->toBe([600, 1500, 600])
        ->and($reversals->pluck('note')->unique()->all())->toBe(['Duplicate invoice'])
        ->and($reversals->pluck('reference_type')->unique()->all())->toBe(['sale']);

    // Net ledger effect of the sale is zero for every product.
    $net = StockMovement::query()->where('reference_type', 'sale')->where('reference_id', $sale->id)
        ->selectRaw('product_id, sum(quantity) as net')->groupBy('product_id')->pluck('net', 'product_id');
    expect($net->map(fn ($v) => (int) $v)->unique()->values()->all())->toBe([0]);

    $log = Activity::query()->where('subject_type', 'sale')->where('subject_id', $sale->id)
        ->where('event', 'updated')->latest('id')->first();
    expect($log->attribute_changes['attributes']['void_reason'])->toBe('Duplicate invoice')
        ->and($log->causer_id)->toBe($this->owner->id);
});

test('void requires a reason', function () {
    $sale = confirmedSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/void", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect(fn () => app(VoidSale::class)->execute($this->owner, $sale, '   '))
        ->toThrow(InvalidInputException::class);

    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed);
});

test('voiding a part-paid sale returns the money to customer credit through reversal rows', function () {
    $product = stockedProduct(stock: 5, price: 1000);
    $customer = Customer::factory()->create(['credit_limit' => null]);
    $sale = confirmedSale($this->owner, [[$product, 2]], $customer);
    $payment = recordPayment($this->owner, $customer, 500);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Wrong school'])
        ->assertOk()
        ->assertJsonPath('data.status', 'void')
        ->assertJsonPath('data.amount_paid', 0)
        ->assertJsonPath('data.balance_due', 0);

    $reversal = PaymentAllocation::query()->whereNotNull('reversal_of_id')->sole();

    expect($reversal->amount)->toBe(-500)
        ->and($payment->fresh()->unallocated_amount)->toBe(500)
        ->and($customer->fresh()->credit_balance)->toBe(500)
        ->and($customer->fresh()->outstanding_balance)->toBe(0)
        ->and($product->fresh()->stock_on_hand)->toBe(5);
});

test('a delivered sale cannot be voided', function () {
    $product = stockedProduct(stock: 10);
    $sale = confirmedSale($this->owner, [[$product, 4]]);
    app(MarkDelivered::class)->execute($this->owner, $sale);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Changed mind'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_delivered')
        ->assertJsonPath('details.sale_id', $sale->id);

    expect($sale->fresh()->status)->toBe(SaleStatus::Confirmed)
        ->and($sale->fresh()->balance_due)->toBe($sale->total)
        ->and($product->fresh()->stock_on_hand)->toBe(6)
        ->and(StockMovement::query()->where('type', StockMovementType::SaleVoidIn)->count())->toBe(0);
});

test('a sale moved to another customer after it was loaded returns 409 sale_state_conflict', function (string $actionClass, array $args) {
    $product = stockedProduct(stock: 10);
    $original = Customer::factory()->create();
    $stale = makeDraftSale($this->owner, [[$product, 1]], $original);

    // Another request reassigns the draft after this one loaded it.
    app(UpdateDraftSale::class)->execute($this->owner, $stale->fresh(), ['customer_id' => Customer::factory()->create()->id]);

    expect(fn () => app($actionClass)->execute($this->owner, $stale, ...$args))
        ->toThrow(SaleStateConflictException::class);

    expect($stale->fresh()->status)->toBe(SaleStatus::Draft)
        ->and($product->fresh()->stock_on_hand)->toBe(10)
        ->and(StockMovement::query()->count())->toBe(0);
})->with([
    'confirm' => [ConfirmSale::class, []],
    'void' => [VoidSale::class, ['reason']],
]);

test('sale_state_conflict renders as a retryable 409', function () {
    $sale = Sale::factory()->create(['created_by' => $this->owner->id]);
    $e = new SaleStateConflictException($sale);

    expect($e->status())->toBe(409)
        ->and($e->toEnvelope()['code'])->toBe('sale_state_conflict')
        ->and($e->details())->toBe(['sale_id' => $sale->id, 'retry' => true]);
});

test('voiding a draft via api returns 409', function () {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'sale_not_editable');
});

// --- Deliver -------------------------------------------------------------------------------

test('deliver sets delivered_at once and is idempotent', function () {
    $sale = confirmedSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/deliver")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $first = $sale->fresh()->delivered_at;
    Carbon::setTestNow('2026-10-03 15:00:00');

    $again = app(MarkDelivered::class)->execute($this->owner, $sale);

    expect($first)->not->toBeNull()
        ->and($again->delivered_at->equalTo($first))->toBeTrue();
});

test('only confirmed sales can be delivered', function () {
    $sale = makeDraftSale($this->owner, [[stockedProduct(stock: 5), 1]]);

    $this->withToken($this->token)->postJson("/api/v1/sales/{$sale->id}/deliver")
        ->assertStatus(409)
        ->assertJsonPath('details.action', 'deliver');
});

// --- Item immutability -------------------------------------------------------------------------

test('sale items cannot be added, changed or deleted once the sale leaves draft', function () {
    $product = stockedProduct(stock: 10);
    $sale = confirmedSale($this->owner, [[$product, 2]]);
    $item = $sale->items->first();

    expect(fn () => $item->update(['unit_price' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $item->delete())->toThrow(LogicException::class)
        ->and(fn () => SaleItem::query()->create([...$item->only($item->getFillable()), 'quantity' => 1]))
        ->toThrow(LogicException::class);
});

test('no action ever changes the items of a confirmed sale', function () {
    $product = stockedProduct(stock: 10, price: 1000);
    $sale = confirmedSale($this->owner, [[$product, 2], [$product, 1, 800, 'Promo']]);
    $snapshot = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->get()->toArray();

    $product->update(['selling_price' => 5000, 'cost_price' => 4000, 'title' => 'Renamed']);

    $attempts = [
        fn () => app(UpdateDraftSale::class)->execute($this->owner, $sale, ['items' => [['product_id' => $product->id, 'quantity' => 9]]]),
        fn () => app(ConfirmSale::class)->execute($this->owner, $sale),
        fn () => app(CancelSale::class)->execute($this->owner, $sale),
    ];
    foreach ($attempts as $attempt) {
        expect($attempt)->toThrow(SaleNotEditableException::class);
    }

    app(VoidSale::class)->execute($this->owner, $sale->fresh(), 'Test');

    foreach ([UpdateDraftSale::class, ConfirmSale::class, CancelSale::class, MarkDelivered::class] as $actionClass) {
        expect(fn () => app($actionClass)->execute($this->owner, $sale->fresh(), ...($actionClass === UpdateDraftSale::class ? [['notes' => 'x']] : [])))
            ->toThrow(SaleNotEditableException::class);
    }

    $after = SaleItem::query()->where('sale_id', $sale->id)->orderBy('id')->get()->toArray();
    expect($after)->toBe($snapshot);
});
