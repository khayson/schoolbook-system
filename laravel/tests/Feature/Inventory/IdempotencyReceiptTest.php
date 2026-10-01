<?php

use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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

test('unknown product on receipt returns 422 and does not store the idempotency key', function () {
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

    expect(IdempotencyKey::query()->where('key', 'receipt-missing-product')->exists())->toBeFalse();

    // Same key can be reused after a non-2xx (corrected payload).
    $product = Product::factory()->create(['stock_on_hand' => 0]);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'receipt-missing-product')
        ->postJson('/api/v1/stock/receipts', [
            'received_at' => '2026-04-01T09:00:00Z',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])
        ->assertCreated();
});

test('failed action releases the idempotency claim so the key can be reused', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/v1/__test/idempotency-throw', function () {
            throw new RuntimeException('boom');
        });

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'throw-key-1')
        ->postJson('/api/v1/__test/idempotency-throw', ['n' => 1])
        ->assertStatus(500);

    expect(IdempotencyKey::query()->where('key', 'throw-key-1')->exists())->toBeFalse();

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/v1/__test/idempotency-ok', fn () => response()->json(['ok' => true], 200));

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'throw-key-1')
        ->postJson('/api/v1/__test/idempotency-ok', ['n' => 1])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('in-progress idempotency claim returns request_in_progress', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $payload = ['n' => 1];
    $hash = hash(
        'sha256',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    IdempotencyKey::query()->create([
        'key' => 'in-progress-key',
        'user_id' => $user->id,
        'route' => 'POST /api/v1/__test/idempotency-busy',
        'request_hash' => $hash,
        'response_status' => 0,
        'response_body' => null,
    ]);

    Route::middleware(['api', 'auth:sanctum', 'idempotent'])
        ->post('/api/v1/__test/idempotency-busy', fn () => response()->json(['ok' => true]));

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'in-progress-key')
        ->postJson('/api/v1/__test/idempotency-busy', $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'request_in_progress');
});

test('idempotency prune command deletes keys older than retention window', function () {
    $user = User::factory()->owner()->create();

    $old = IdempotencyKey::query()->create([
        'key' => 'old-key',
        'user_id' => $user->id,
        'route' => 'POST /api/v1/stock/receipts',
        'request_hash' => hash('sha256', 'old'),
        'response_status' => 201,
        'response_body' => '{}',
    ]);
    $old->forceFill(['created_at' => now()->subHours(73), 'updated_at' => now()->subHours(73)])->save();

    $fresh = IdempotencyKey::query()->create([
        'key' => 'fresh-key',
        'user_id' => $user->id,
        'route' => 'POST /api/v1/stock/receipts',
        'request_hash' => hash('sha256', 'fresh'),
        'response_status' => 201,
        'response_body' => '{}',
    ]);

    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(IdempotencyKey::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(IdempotencyKey::query()->whereKey($fresh->id)->exists())->toBeTrue();
});
