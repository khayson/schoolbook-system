<?php

use App\DTOs\Pricing\PriceLineInput;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    TaxRate::query()->delete();
    TaxRate::query()->create(['code' => 'vat', 'name' => 'VAT', 'rate_bps' => 1500, 'is_active' => true, 'sort_order' => 1]);
    TaxRate::query()->create(['code' => 'nhil', 'name' => 'NHIL', 'rate_bps' => 250, 'is_active' => true, 'sort_order' => 2]);
    TaxRate::query()->create(['code' => 'getfund', 'name' => 'GETFund', 'rate_bps' => 250, 'is_active' => true, 'sort_order' => 3]);
});

test('tax disabled keeps tax_total at zero', function () {
    Setting::setValue('tax_enabled', false);
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 4000]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], Carbon::parse('2026-10-18'));

    expect($priced->taxTotal)->toBe(0)
        ->and($priced->total)->toBe(10000)
        ->and($priced->lines[0]->taxAmount)->toBe(0);
});

test('tax enabled applies rates independently on exclusive net', function () {
    Setting::setValue('tax_enabled', true);
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 4000]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], Carbon::parse('2026-10-18'));

    // 15% + 2.5% + 2.5% = 20% of 10000 = 2000
    expect($priced->subtotal)->toBe(10000)
        ->and($priced->taxTotal)->toBe(2000)
        ->and($priced->total)->toBe(12000)
        ->and($priced->lines[0]->taxAmount)->toBe(2000);
});

test('pricing preview reflects tax when enabled', function () {
    Setting::setValue('tax_enabled', true);
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;
    $product = Product::factory()->create(['selling_price' => 5000, 'cost_price' => 1000]);

    $this->withToken($token)
        ->postJson('/api/v1/pricing/preview', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])
        ->assertOk()
        ->assertJsonPath('data.subtotal', 10000)
        ->assertJsonPath('data.tax_total', 2000)
        ->assertJsonPath('data.total', 12000);
});
