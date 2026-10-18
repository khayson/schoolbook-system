<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockExchange;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-18 15:00:00');
    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
    $this->customer = Customer::factory()->create(['credit_limit' => 5_000_000]);
});

afterEach(fn () => Carbon::setTestNow());

test('stock exchange api create confirm and list', function () {
    $in = Product::factory()->create(['cost_price' => 900, 'selling_price' => 1800]);
    $in->stock_on_hand = 0;
    $in->save();

    $out = Product::factory()->create(['selling_price' => 2000, 'cost_price' => 1000]);
    $out->stock_on_hand = 10;
    $out->save();

    $create = $this->withToken($this->token)
        ->withHeader('Idempotency-Key', 'exc-api-1')
        ->postJson('/api/v1/stock/exchanges', [
            'customer_id' => $this->customer->id,
            'counterparty_name' => 'Market Kojo',
            'settlement' => 'installment',
            'items' => [
                ['direction' => 'in', 'product_id' => $in->id, 'quantity' => 2, 'unit_cost' => 950],
                ['direction' => 'out', 'product_id' => $out->id, 'quantity' => 1, 'unit_price' => 2000],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.counterparty_name', 'Market Kojo');

    $id = $create->json('data.id');

    $this->withToken($this->token)
        ->withHeader('Idempotency-Key', 'exc-api-1-confirm')
        ->postJson("/api/v1/stock/exchanges/{$id}/confirm", [])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.sale.total', 2000);

    expect($in->fresh()->stock_on_hand)->toBe(2)
        ->and($out->fresh()->stock_on_hand)->toBe(9);

    $this->withToken($this->token)
        ->getJson('/api/v1/stock/exchanges?status=confirmed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $id);
});

test('stock exchange api cancel draft returns 204', function () {
    $product = Product::factory()->create();

    $id = $this->withToken($this->token)
        ->withHeader('Idempotency-Key', 'exc-cancel')
        ->postJson('/api/v1/stock/exchanges', [
            'customer_id' => $this->customer->id,
            'counterparty_name' => 'Temp',
            'settlement' => 'pay_later',
            'items' => [
                ['direction' => 'in', 'product_id' => $product->id, 'quantity' => 1],
            ],
        ])
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson("/api/v1/stock/exchanges/{$id}/cancel")
        ->assertNoContent();

    expect(StockExchange::query()->find($id))->toBeNull();
});
