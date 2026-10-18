<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\ConfirmSaleReturn;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\VoidSaleReturn;
use App\Enums\PaymentMethod;
use App\Enums\RestockCondition;
use App\Enums\SaleReturnStatus;
use App\Enums\StockMovementType;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');
    Setting::setValue('allow_negative_stock', false);
    Setting::setValue('default_payment_terms_days', 30);
    $this->owner = User::factory()->owner()->create();
});

afterEach(function () {
    assertMoneyInvariants();
    Carbon::setTestNow();
});

test('confirming a good return restocks and credits the customer', function () {
    $product = stockedProduct(10, 5000, 3000);
    $sale = makeDraftSale($this->owner, [[$product, 4]]);
    $sale = app(ConfirmSale::class)->execute($this->owner, $sale);
    expect($product->fresh()->stock_on_hand)->toBe(6)
        ->and($sale->fresh()->balance_due)->toBe(20000);

    $item = $sale->items()->first();
    $return = app(CreateSaleReturn::class)->execute($this->owner, [
        'sale_id' => $sale->id,
        'reason' => 'Wrong titles',
        'items' => [[
            'sale_item_id' => $item->id,
            'quantity' => 2,
            'restock_condition' => RestockCondition::Good->value,
        ]],
    ]);

    expect($return->status)->toBe(SaleReturnStatus::Draft)
        ->and($return->total)->toBe(10000);

    $confirmed = app(ConfirmSaleReturn::class)->execute($this->owner, $return);

    expect($confirmed->status)->toBe(SaleReturnStatus::Confirmed)
        ->and($confirmed->return_no)->toStartWith('SRN-')
        ->and($product->fresh()->stock_on_hand)->toBe(8)
        ->and($sale->fresh()->balance_due)->toBe(10000);

    expect(StockMovement::query()->where('type', StockMovementType::ReturnIn)->count())->toBe(1);
    expect(Payment::query()->where('method', PaymentMethod::ReturnCredit)->where('reference', $confirmed->return_no)->exists())->toBeTrue();
});

test('damaged return does not increase sellable stock but still credits', function () {
    $product = stockedProduct(5, 4000, 2000);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 2]]));
    $item = $sale->items()->first();

    $return = app(CreateSaleReturn::class)->execute($this->owner, [
        'sale_id' => $sale->id,
        'items' => [[
            'sale_item_id' => $item->id,
            'quantity' => 1,
            'restock_condition' => RestockCondition::Damaged->value,
        ]],
    ]);
    app(ConfirmSaleReturn::class)->execute($this->owner, $return, 'Water damage');

    expect($product->fresh()->stock_on_hand)->toBe(3)
        ->and($sale->fresh()->balance_due)->toBe(4000)
        ->and(StockMovement::query()->where('type', StockMovementType::Damage)->where('note', 'like', '%audit only%')->count())->toBe(1);
});

test('voiding a good return reverses stock and voids the credit payment', function () {
    $product = stockedProduct(8, 5000, 3000);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 3]]));
    $item = $sale->items()->first();
    $return = app(CreateSaleReturn::class)->execute($this->owner, [
        'sale_id' => $sale->id,
        'items' => [[
            'sale_item_id' => $item->id,
            'quantity' => 1,
            'restock_condition' => RestockCondition::Good->value,
        ]],
    ]);
    $confirmed = app(ConfirmSaleReturn::class)->execute($this->owner, $return, 'Customer changed mind');
    expect($product->fresh()->stock_on_hand)->toBe(6);

    app(VoidSaleReturn::class)->execute($this->owner, $confirmed, 'Entered in error');

    expect($confirmed->fresh()->status)->toBe(SaleReturnStatus::Void)
        ->and($product->fresh()->stock_on_hand)->toBe(5)
        ->and($sale->fresh()->balance_due)->toBe(15000);
});

test('sale return api create confirm and void', function () {
    $token = $this->owner->createToken('test')->plainTextToken;
    $product = stockedProduct(10, 2000, 1000);
    $sale = app(ConfirmSale::class)->execute($this->owner, makeDraftSale($this->owner, [[$product, 2]]));
    $item = $sale->items()->first();

    $create = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'ret-1')
        ->postJson('/api/v1/sale-returns', [
            'sale_id' => $sale->id,
            'items' => [[
                'sale_item_id' => $item->id,
                'quantity' => 1,
                'restock_condition' => 'good',
            ]],
        ])
        ->assertSuccessful();

    $id = $create->json('data.id');

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'ret-1-confirm')
        ->postJson("/api/v1/sale-returns/{$id}/confirm", ['reason' => 'API return'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');

    expect($product->fresh()->stock_on_hand)->toBe(9);
});
