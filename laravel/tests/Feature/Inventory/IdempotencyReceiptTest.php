<?php

use App\Models\Product;
use App\Models\User;

test('receipt requires idempotency key', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $this->withToken($token)
        ->postJson('/api/v1/stock/receipts', [
            'received_at' => '2026-04-01T09:00:00Z',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_required');
});

test('receipt replays identical idempotent request and rejects mismatched payload', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $payload = [
        'received_at' => '2026-04-01T09:00:00Z',
        'notes' => 'Morning delivery',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 12, 'unit_cost' => 4800],
        ],
    ];

    $first = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receipt-key-1')
        ->postJson('/api/v1/stock/receipts', $payload);

    $first->assertCreated()
        ->assertJsonPath('data.receipt_no', 'GRN-2026-000001');

    $replay = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receipt-key-1')
        ->postJson('/api/v1/stock/receipts', $payload);

    $replay->assertCreated()
        ->assertJsonPath('data.receipt_no', 'GRN-2026-000001')
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($product->fresh()->stock_on_hand)->toBe(12);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receipt-key-1')
        ->postJson('/api/v1/stock/receipts', [
            ...$payload,
            'notes' => 'Different notes',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_mismatch');

    expect($product->fresh()->stock_on_hand)->toBe(12);
});

test('unknown product on receipt returns 422', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receipt-missing-product')
        ->postJson('/api/v1/stock/receipts', [
            'received_at' => '2026-04-01T09:00:00Z',
            'items' => [
                ['product_id' => 999999, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.product_id']);
});
