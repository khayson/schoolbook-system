<?php

use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use App\Models\PriceRule;
use Illuminate\Support\Carbon;

test('price rule casts enums and integers', function () {
    $rule = PriceRule::factory()->create([
        'type' => PriceRuleType::AmountOff,
        'value' => 500,
        'scope_type' => PriceRuleScopeType::Publisher,
        'scope_id' => 7,
        'customer_scope' => PriceRuleCustomerScope::CustomerType,
        'customer_scope_value' => 'school',
        'min_quantity' => 2,
        'max_quantity' => 10,
        'min_order_total' => 10000,
        'priority' => 50,
        'stackable' => true,
        'floor_policy' => PriceRuleFloorPolicy::BlockBelowCost,
        'is_active' => true,
    ]);

    $rule->refresh();

    expect($rule->type)->toBe(PriceRuleType::AmountOff)
        ->and($rule->value)->toBe(500)
        ->and($rule->scope_type)->toBe(PriceRuleScopeType::Publisher)
        ->and($rule->scope_id)->toBe(7)
        ->and($rule->customer_scope)->toBe(PriceRuleCustomerScope::CustomerType)
        ->and($rule->customer_scope_value)->toBe('school')
        ->and($rule->min_quantity)->toBe(2)
        ->and($rule->max_quantity)->toBe(10)
        ->and($rule->min_order_total)->toBe(10000)
        ->and($rule->priority)->toBe(50)
        ->and($rule->stackable)->toBeTrue()
        ->and($rule->floor_policy)->toBe(PriceRuleFloorPolicy::BlockBelowCost)
        ->and($rule->is_active)->toBeTrue();
});

test('active and effectiveOn scopes filter correctly', function () {
    PriceRule::factory()->create([
        'name' => 'active-open',
        'is_active' => true,
        'starts_at' => null,
        'ends_at' => null,
    ]);
    PriceRule::factory()->inactive()->create([
        'name' => 'inactive',
        'starts_at' => null,
        'ends_at' => null,
    ]);
    PriceRule::factory()->create([
        'name' => 'future',
        'is_active' => true,
        'starts_at' => Carbon::parse('2026-06-01'),
        'ends_at' => null,
    ]);
    PriceRule::factory()->create([
        'name' => 'expired',
        'is_active' => true,
        'starts_at' => Carbon::parse('2026-01-01'),
        'ends_at' => Carbon::parse('2026-03-01'),
    ]);
    PriceRule::factory()->create([
        'name' => 'windowed',
        'is_active' => true,
        'starts_at' => Carbon::parse('2026-03-01'),
        'ends_at' => Carbon::parse('2026-05-01'),
    ]);

    $date = Carbon::parse('2026-04-15');
    $ids = PriceRule::query()->active()->effectiveOn($date)->pluck('name')->sort()->values()->all();

    expect($ids)->toBe(['active-open', 'windowed']);
});

test('lineLevel and orderLevel scopes split by type', function () {
    PriceRule::factory()->percentOff(1000)->create(['name' => 'line-pct']);
    PriceRule::factory()->amountOff(100)->create(['name' => 'line-amt']);
    PriceRule::factory()->fixedPrice(5000)->create(['name' => 'line-fix']);
    PriceRule::factory()->orderPercentOff(500)->create(['name' => 'order-pct']);
    PriceRule::factory()->orderAmountOff(200)->create(['name' => 'order-amt']);
    PriceRule::factory()->create([
        'name' => 'bonus',
        'type' => PriceRuleType::BonusUnits,
        'value' => 1,
    ]);

    expect(PriceRule::query()->lineLevel()->pluck('name')->sort()->values()->all())
        ->toBe(['line-amt', 'line-fix', 'line-pct'])
        ->and(PriceRule::query()->orderLevel()->pluck('name')->sort()->values()->all())
        ->toBe(['order-amt', 'order-pct']);
});

test('specificity combines scope and customer scope ranks', function () {
    $productCustomer = PriceRule::factory()->make([
        'scope_type' => PriceRuleScopeType::Product,
        'customer_scope' => PriceRuleCustomerScope::Customer,
    ]);
    $allAll = PriceRule::factory()->make([
        'scope_type' => PriceRuleScopeType::All,
        'customer_scope' => PriceRuleCustomerScope::All,
    ]);

    expect($productCustomer->specificity())->toBe(100)
        ->and($allAll->specificity())->toBe(20)
        ->and($productCustomer->specificity())->toBeGreaterThan($allAll->specificity());
});

test('floor policy strictest wins', function () {
    expect(PriceRuleFloorPolicy::strictest(
        PriceRuleFloorPolicy::Allow,
        PriceRuleFloorPolicy::Warn,
        PriceRuleFloorPolicy::BlockBelowCost,
    ))->toBe(PriceRuleFloorPolicy::BlockBelowCost)
        ->and(PriceRuleFloorPolicy::strictest(
            PriceRuleFloorPolicy::Allow,
            PriceRuleFloorPolicy::Warn,
        ))->toBe(PriceRuleFloorPolicy::Warn);
});
