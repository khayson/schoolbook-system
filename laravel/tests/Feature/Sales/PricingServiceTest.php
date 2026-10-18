<?php

use App\DTOs\Pricing\PriceLineInput;
use App\Enums\CustomerType;
use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\PriceRule;
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

test('percent_off reduces unit price with half-up rounding and records the rule', function () {
    $product = Product::factory()->create(['selling_price' => 1000, 'cost_price' => 400]);
    PriceRule::factory()->percentOff(1000)->create([
        'name' => '10% off all',
        'priority' => 10,
    ]);

    $priced = app(PricingService::class)->priceLines(
        null,
        [new PriceLineInput($product->id, 2)],
        Carbon::parse('2026-04-01'),
    );

    expect($priced->lines[0]->unitPrice)->toBe(900)
        ->and($priced->lines[0]->discountPerUnit)->toBe(100)
        ->and($priced->lines[0]->lineTotal)->toBe(1800)
        ->and($priced->lines[0]->appliedRules)->toHaveCount(1)
        ->and($priced->lines[0]->appliedRules[0]->name)->toBe('10% off all');
});

test('higher priority wins over more specific when priority differs', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(5000)->forProduct($product->id)->create([
        'name' => 'product 50%',
        'priority' => 1,
    ]);
    PriceRule::factory()->percentOff(1000)->create([
        'name' => 'global 10%',
        'priority' => 100,
    ]);

    $priced = app(PricingService::class)->priceLines(
        null,
        [new PriceLineInput($product->id, 1)],
        now(),
    );

    expect($priced->lines[0]->unitPrice)->toBe(9000)
        ->and($priced->lines[0]->appliedRules[0]->name)->toBe('global 10%');
});

test('equal priority prefers higher specificity', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(1000)->create([
        'name' => 'all 10%',
        'priority' => 5,
        'scope_type' => PriceRuleScopeType::All,
    ]);
    PriceRule::factory()->percentOff(2000)->forProduct($product->id)->create([
        'name' => 'product 20%',
        'priority' => 5,
    ]);

    $priced = app(PricingService::class)->priceLines(
        null,
        [new PriceLineInput($product->id, 1)],
        now(),
    );

    expect($priced->lines[0]->unitPrice)->toBe(8000)
        ->and($priced->lines[0]->appliedRules[0]->name)->toBe('product 20%');
});

test('non-stackable first rule stops the chain', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(1000)->create(['name' => 'first', 'priority' => 20, 'stackable' => false]);
    PriceRule::factory()->percentOff(1000)->create(['name' => 'second', 'priority' => 10, 'stackable' => true]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());

    expect($priced->lines[0]->unitPrice)->toBe(9000)
        ->and($priced->lines[0]->appliedRules)->toHaveCount(1);
});

test('stackable rules chain until a non-stackable candidate is reached', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(1000)->stackable()->create(['name' => 'stack-a', 'priority' => 30]);
    PriceRule::factory()->amountOff(500)->stackable()->create(['name' => 'stack-b', 'priority' => 20]);
    PriceRule::factory()->percentOff(1000)->create(['name' => 'blocker', 'priority' => 10, 'stackable' => false]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());

    // 10000 -> 9000 (10%) -> 8500 (amount 500); blocker not applied
    expect($priced->lines[0]->unitPrice)->toBe(8500)
        ->and($priced->lines[0]->appliedRules)->toHaveCount(2);
});

test('fixed_price and amount_off work', function () {
    $product = Product::factory()->create(['selling_price' => 8000, 'cost_price' => 1000]);
    PriceRule::factory()->fixedPrice(5500)->forProduct($product->id)->create(['priority' => 10]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());
    expect($priced->lines[0]->unitPrice)->toBe(5500);

    PriceRule::query()->delete();
    PriceRule::factory()->amountOff(300)->create(['priority' => 10]);
    $priced2 = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());
    expect($priced2->lines[0]->unitPrice)->toBe(7700);
});

test('quantity and customer scopes filter candidates', function () {
    $school = Customer::factory()->create(['type' => CustomerType::School]);
    $reseller = Customer::factory()->create(['type' => CustomerType::Reseller]);
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);

    PriceRule::factory()->percentOff(1000)->create([
        'name' => 'school bulk',
        'customer_scope' => PriceRuleCustomerScope::CustomerType,
        'customer_scope_value' => CustomerType::School->value,
        'min_quantity' => 5,
        'priority' => 10,
    ]);

    $service = app(PricingService::class);

    expect($service->priceLines($school, [new PriceLineInput($product->id, 4)], now())->lines[0]->unitPrice)->toBe(10000)
        ->and($service->priceLines($school, [new PriceLineInput($product->id, 5)], now())->lines[0]->unitPrice)->toBe(9000)
        ->and($service->priceLines($reseller, [new PriceLineInput($product->id, 5)], now())->lines[0]->unitPrice)->toBe(10000);
});

test('inactive and out-of-window rules are ignored', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(5000)->inactive()->create(['priority' => 100]);
    PriceRule::factory()->percentOff(5000)->create([
        'priority' => 100,
        'starts_at' => Carbon::parse('2027-01-01'),
    ]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], Carbon::parse('2026-04-01'));
    expect($priced->lines[0]->unitPrice)->toBe(10000);
});

test('floor warn adds warning; block raises; allow is silent', function () {
    $product = Product::factory()->create(['selling_price' => 5000, 'cost_price' => 4000]);

    PriceRule::factory()->fixedPrice(3000)->create([
        'floor_policy' => PriceRuleFloorPolicy::Warn,
        'priority' => 10,
    ]);
    $warned = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());
    expect($warned->lines[0]->unitPrice)->toBe(3000)
        ->and($warned->warnings)->not->toBeEmpty()
        ->and($warned->lines[0]->warnings)->not->toBeEmpty();

    PriceRule::query()->delete();
    PriceRule::factory()->fixedPrice(3000)->create([
        'floor_policy' => PriceRuleFloorPolicy::Allow,
        'priority' => 10,
    ]);
    $allowed = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());
    expect($allowed->warnings)->toBe([]);

    PriceRule::query()->delete();
    PriceRule::factory()->fixedPrice(3000)->create([
        'floor_policy' => PriceRuleFloorPolicy::BlockBelowCost,
        'priority' => 10,
    ]);
    expect(fn () => app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now()))
        ->toThrow(InvalidInputException::class);
});

test('manual override skips rules and keeps reason', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->percentOff(5000)->create(['priority' => 100]);

    $priced = app(PricingService::class)->priceLines(
        null,
        [new PriceLineInput($product->id, 2, 7000, 'VIP deal')],
        now(),
    );

    expect($priced->lines[0]->unitPrice)->toBe(7000)
        ->and($priced->lines[0]->isPriceOverridden)->toBeTrue()
        ->and($priced->lines[0]->overrideReason)->toBe('VIP deal')
        ->and($priced->lines[0]->appliedRules)->toBe([]);
});

test('order-level percent and amount discounts fill discount_total', function () {
    $product = Product::factory()->create(['selling_price' => 10000, 'cost_price' => 1000]);
    PriceRule::factory()->orderPercentOff(500, 15000)->create([
        'name' => '5% order',
        'priority' => 10,
    ]);

    $priced = app(PricingService::class)->priceLines(
        null,
        [new PriceLineInput($product->id, 2)],
        now(),
    );

    expect($priced->subtotal)->toBe(20000)
        ->and($priced->discountTotal)->toBe(1000)
        ->and($priced->total)->toBe(19000);

    PriceRule::query()->delete();
    PriceRule::factory()->orderAmountOff(2500, 10000)->create(['priority' => 10]);
    $priced2 = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 2)], now());
    expect($priced2->discountTotal)->toBe(2500)->and($priced2->total)->toBe(17500);
});

test('order rule below min_order_total is skipped', function () {
    $product = Product::factory()->create(['selling_price' => 1000, 'cost_price' => 100]);
    PriceRule::factory()->orderPercentOff(1000, 50000)->create();

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 2)], now());
    expect($priced->discountTotal)->toBe(0)->and($priced->total)->toBe(2000);
});

test('bonus_units type is ignored until implemented', function () {
    $product = Product::factory()->create(['selling_price' => 5000, 'cost_price' => 1000]);
    PriceRule::factory()->create([
        'type' => PriceRuleType::BonusUnits,
        'value' => 1,
        'priority' => 100,
    ]);

    $priced = app(PricingService::class)->priceLines(null, [new PriceLineInput($product->id, 1)], now());
    expect($priced->lines[0]->unitPrice)->toBe(5000)->and($priced->lines[0]->appliedRules)->toBe([]);
});
