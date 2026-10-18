<?php

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceiveAgainstPurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-18 11:00:00');
    $this->owner = User::factory()->owner()->create();
    $this->supplier = Supplier::factory()->create(['name' => 'Accra Books Ltd']);
});

afterEach(fn () => Carbon::setTestNow());

test('purchase order place and receive increases stock via goods receipt', function () {
    $product = Product::factory()->create(['cost_price' => 1000, 'selling_price' => 2000]);
    $product->stock_on_hand = 0;
    $product->save();

    $po = app(CreatePurchaseOrder::class)->execute($this->owner, [
        'supplier_id' => $this->supplier->id,
        'items' => [
            ['product_id' => $product->id, 'quantity_ordered' => 10, 'unit_cost' => 1200],
        ],
    ]);
    expect($po->status)->toBe(PurchaseOrderStatus::Draft);

    $placed = app(PlacePurchaseOrder::class)->execute($this->owner, $po);
    expect($placed->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($placed->po_no)->toStartWith('PO-');

    $poItemId = $placed->items()->first()->id;

    $receipt = app(ReceiveAgainstPurchaseOrder::class)->execute($this->owner, $placed, [
        'received_at' => now()->toIso8601String(),
        'items' => [
            ['purchase_order_item_id' => $poItemId, 'quantity' => 6],
        ],
    ]);

    expect($receipt->purchase_order_id)->toBe($placed->id)
        ->and($product->fresh()->stock_on_hand)->toBe(6)
        ->and($placed->fresh()->status)->toBe(PurchaseOrderStatus::Partial);

    app(ReceiveAgainstPurchaseOrder::class)->execute($this->owner, $placed->fresh(), [
        'received_at' => now()->toIso8601String(),
        'items' => [
            ['purchase_order_item_id' => $poItemId, 'quantity' => 4],
        ],
    ]);

    expect($placed->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($product->fresh()->stock_on_hand)->toBe(10);
});

test('suppliers and purchase orders api', function () {
    $token = $this->owner->createToken('test')->plainTextToken;
    $product = Product::factory()->create();

    $this->withToken($token)
        ->postJson('/api/v1/suppliers', [
            'name' => 'Tema Press',
            'phone' => '0200000000',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Tema Press');

    $create = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'po-1')
        ->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $product->id, 'quantity_ordered' => 5, 'unit_cost' => 800],
            ],
        ])
        ->assertSuccessful();

    $id = $create->json('data.id');

    $this->withToken($token)
        ->postJson("/api/v1/purchase-orders/{$id}/place")
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'ordered');

    $poItemId = PurchaseOrder::query()->findOrFail($id)->items()->first()->id;

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'po-1-recv')
        ->postJson("/api/v1/purchase-orders/{$id}/receive", [
            'received_at' => now()->toIso8601String(),
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'quantity' => 5],
            ],
        ])
        ->assertSuccessful();

    expect($product->fresh()->stock_on_hand)->toBe(5);
});
