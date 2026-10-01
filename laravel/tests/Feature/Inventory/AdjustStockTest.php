<?php

use App\Actions\Inventory\AdjustStock;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;

test('adjustment changes stock with note', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 20]);

    $movement = app(AdjustStock::class)->execute(
        $user,
        $product->id,
        -5,
        StockMovementType::Adjustment,
        'Found damaged box during shelf check',
    );

    expect($movement->note)->toBe('Found damaged box during shelf check')
        ->and($movement->balance_after)->toBe(15);

    expect($product->fresh()->stock_on_hand)->toBe(15);

    expect(StockMovement::query()->count())->toBe(1);
});

test('negative stock blocked when setting false', function () {
    Setting::setValue('allow_negative_stock', false);

    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 3]);

    app(AdjustStock::class)->execute(
        $user,
        $product->id,
        -10,
        StockMovementType::Damage,
        'Water damage',
    );
})->throws(DomainException::class);

test('negative stock allowed when allow_negative_stock true', function () {
    Setting::setValue('allow_negative_stock', true);

    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 3]);

    $movement = app(AdjustStock::class)->execute(
        $user,
        $product->id,
        -10,
        StockMovementType::Damage,
        'Emergency write-off',
    );

    expect($movement->balance_after)->toBe(-7)
        ->and($product->fresh()->stock_on_hand)->toBe(-7);
});

test('negative stock blocked via api when setting false', function () {
    Setting::setValue('allow_negative_stock', false);

    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['stock_on_hand' => 2]);

    $this->withToken($token)->postJson('/api/v1/stock/adjustments', [
        'product_id' => $product->id,
        'quantity' => -5,
        'type' => 'adjustment',
        'note' => 'Correction',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_stock');

    expect($product->fresh()->stock_on_hand)->toBe(2);
});
