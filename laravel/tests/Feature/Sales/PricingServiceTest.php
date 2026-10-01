<?php

use App\DTOs\Pricing\PriceLineInput;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;

test('pricing service returns base selling prices with no rules', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create([
        'selling_price' => 6000,
        'cost_price' => 4000,
        'title' => 'Math Book',
    ]);

    $priced = app(PricingService::class)->priceLines(
        $customer,
        [new PriceLineInput($product->id, 3)],
        Carbon::parse('2026-04-01'),
    );

    expect($priced->lines)->toHaveCount(1)
        ->and($priced->subtotal)->toBe(18000)
        ->and($priced->discountTotal)->toBe(0)
        ->and($priced->total)->toBe(18000)
        ->and($priced->lines[0]->basePrice)->toBe(6000)
        ->and($priced->lines[0]->unitPrice)->toBe(6000)
        ->and($priced->lines[0]->lineTotal)->toBe(18000)
        ->and($priced->lines[0]->appliedRules)->toBe([]);
});

test('pricing preview endpoint uses pricing service', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['selling_price' => 2500]);

    $this->withToken($token)
        ->postJson('/api/v1/pricing/preview', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.total', 10000)
        ->assertJsonPath('data.lines.0.unit_price', 2500);
});
