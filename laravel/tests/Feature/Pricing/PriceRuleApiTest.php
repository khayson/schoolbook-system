<?php

use App\Enums\PriceRuleType;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

test('owner can crud price rules', function () {
    $create = $this->withToken($this->token)
        ->postJson('/api/v1/price-rules', [
            'name' => 'School 10%',
            'type' => 'percent_off',
            'value' => 1000,
            'scope_type' => 'all',
            'customer_scope' => 'all',
            'priority' => 10,
            'stackable' => false,
            'floor_policy' => 'warn',
            'is_active' => true,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'School 10%')
        ->assertJsonPath('data.type', 'percent_off')
        ->assertJsonPath('data.value', 1000);

    $id = $create->json('data.id');

    $this->withToken($this->token)
        ->getJson('/api/v1/price-rules')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);

    $this->withToken($this->token)
        ->putJson("/api/v1/price-rules/{$id}", [
            'value' => 1500,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.value', 1500)
        ->assertJsonPath('data.is_active', false);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/price-rules/{$id}")
        ->assertOk();

    expect(PriceRule::query()->find($id))->toBeNull();
});

test('creating a rule changes pricing preview immediately', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 2000]);

    $this->withToken($this->token)
        ->postJson('/api/v1/pricing/preview', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
        ->assertOk()
        ->assertJsonPath('data.total', 10000);

    PriceRule::factory()->percentOff(2000)->create(['name' => '20% now', 'priority' => 50]);

    $this->withToken($this->token)
        ->postJson('/api/v1/pricing/preview', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
        ->assertOk()
        ->assertJsonPath('data.total', 8000)
        ->assertJsonPath('data.lines.0.applied_rules.0.name', '20% now');
});

test('scope_id required when scope is not all', function () {
    $this->withToken($this->token)
        ->postJson('/api/v1/price-rules', [
            'name' => 'Bad',
            'type' => PriceRuleType::PercentOff->value,
            'value' => 1000,
            'scope_type' => 'product',
            'customer_scope' => 'all',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scope_id']);
});
