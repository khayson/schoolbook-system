<?php

use App\Actions\Inventory\ReceiveStock;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\NumberSequence;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('receipt increases stock and writes balanced movements', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create([
        'stock_on_hand' => 10,
        'cost_price' => 1000,
    ]);

    $receivedAt = Carbon::parse('2026-03-15 10:00:00');

    $receipt = app(ReceiveStock::class)->execute($user, [
        'received_at' => $receivedAt,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 25, 'unit_cost' => 5500],
        ],
    ]);

    expect($receipt->receipt_no)->toBe('GRN-2026-000001');

    $product->refresh();
    expect($product->stock_on_hand)->toBe(35);

    $movement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::ReceiptIn)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(25)
        ->and($movement->balance_after)->toBe(35)
        ->and($movement->unit_cost)->toBe(5500);
});

test('cost_price updated to latest unit_cost', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create([
        'stock_on_hand' => 0,
        'cost_price' => 2000,
    ]);

    app(ReceiveStock::class)->execute($user, [
        'received_at' => '2026-01-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 3200],
        ],
    ]);

    app(ReceiveStock::class)->execute($user, [
        'received_at' => '2026-02-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 4100],
        ],
    ]);

    expect($product->fresh()->cost_price)->toBe(4100);
});

test('owner can receive stock via api', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $response = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receive-stock-api-1')
        ->postJson('/api/v1/stock/receipts', [
            'received_at' => '2026-04-01T09:00:00Z',
            'notes' => 'Morning delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 12, 'unit_cost' => 4800],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.receipt_no', 'GRN-2026-000001')
        ->assertJsonPath('data.items.0.quantity', 12);

    expect($product->fresh()->stock_on_hand)->toBe(12);
});

test('rejects non-positive quantity and negative unit cost', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 0]);
    $receive = app(ReceiveStock::class);

    expect(fn () => $receive->execute($user, [
        'received_at' => '2026-04-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 0, 'unit_cost' => 100],
        ],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $receive->execute($user, [
        'received_at' => '2026-04-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => -1],
        ],
    ]))->toThrow(InvalidArgumentException::class);
});

test('merges identical cost lines but keeps separate costs and locks each product once', function () {
    $user = User::factory()->owner()->create();
    $first = Product::factory()->create(['stock_on_hand' => 0, 'cost_price' => 100]);
    $second = Product::factory()->create(['stock_on_hand' => 0, 'cost_price' => 100]);

    $receipt = app(ReceiveStock::class)->execute($user, [
        'received_at' => '2026-04-01',
        'items' => [
            ['product_id' => $second->id, 'quantity' => 3, 'unit_cost' => 1000],
            ['product_id' => $first->id, 'quantity' => 10, 'unit_cost' => 5000],
            ['product_id' => $first->id, 'quantity' => 10, 'unit_cost' => 6000],
            ['product_id' => $first->id, 'quantity' => 2, 'unit_cost' => 5000],
        ],
    ]);

    expect($receipt->items)->toHaveCount(3);

    $firstLines = $receipt->items->where('product_id', $first->id)->values();
    expect($firstLines)->toHaveCount(2)
        ->and($firstLines->firstWhere('unit_cost', 5000)->quantity)->toBe(12)
        ->and($firstLines->firstWhere('unit_cost', 6000)->quantity)->toBe(10);

    expect($first->fresh()->stock_on_hand)->toBe(22)
        ->and($first->fresh()->cost_price)->toBe(6000)
        ->and($second->fresh()->stock_on_hand)->toBe(3);

    $movements = StockMovement::query()
        ->where('product_id', $first->id)
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->quantity)->toBe(12)
        ->and($movements[0]->unit_cost)->toBe(5000)
        ->and($movements[0]->balance_after)->toBe(12)
        ->and($movements[1]->quantity)->toBe(10)
        ->and($movements[1]->unit_cost)->toBe(6000)
        ->and($movements[1]->balance_after)->toBe(22)
        ->and($movements[0]->reference_type)->toBe('goods_receipt');
});

test('stock movements cannot be updated or deleted', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    app(ReceiveStock::class)->execute($user, [
        'received_at' => '2026-04-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
        ],
    ]);

    $movement = StockMovement::query()->firstOrFail();

    expect(fn () => $movement->update(['note' => 'tamper']))
        ->toThrow(LogicException::class);

    expect(fn () => $movement->delete())
        ->toThrow(LogicException::class);
});

test('rolled back receipt does not burn a GRN sequence number', function () {
    $user = User::factory()->owner()->create();
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    try {
        DB::transaction(function () use ($user, $product) {
            app(ReceiveStock::class)->execute($user, [
                'received_at' => '2026-04-01',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000],
                ],
            ]);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and((int) (NumberSequence::query()->where('key', 'grn')->where('year', 2026)->value('last_number') ?? 0))
        ->toBe(0);

    $receipt = app(ReceiveStock::class)->execute($user, [
        'received_at' => '2026-04-01',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 1000],
        ],
    ]);

    expect($receipt->receipt_no)->toBe('GRN-2026-000001');
});
