<?php

namespace Database\Factories;

use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use App\Models\PriceRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    protected $model = PriceRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' discount',
            'type' => PriceRuleType::PercentOff,
            'value' => 1000, // 10.00%
            'scope_type' => PriceRuleScopeType::All,
            'scope_id' => null,
            'customer_scope' => PriceRuleCustomerScope::All,
            'customer_scope_value' => null,
            'min_quantity' => null,
            'max_quantity' => null,
            'min_order_total' => null,
            'starts_at' => null,
            'ends_at' => null,
            'priority' => 0,
            'stackable' => false,
            'floor_policy' => PriceRuleFloorPolicy::Warn,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        return PriceRule::unguarded(fn () => parent::create($attributes, $parent));
    }

    public function percentOff(int $basisPoints): static
    {
        return $this->state(fn (): array => [
            'type' => PriceRuleType::PercentOff,
            'value' => $basisPoints,
        ]);
    }

    public function amountOff(int $pesewas): static
    {
        return $this->state(fn (): array => [
            'type' => PriceRuleType::AmountOff,
            'value' => $pesewas,
        ]);
    }

    public function fixedPrice(int $pesewas): static
    {
        return $this->state(fn (): array => [
            'type' => PriceRuleType::FixedPrice,
            'value' => $pesewas,
        ]);
    }

    public function orderPercentOff(int $basisPoints, ?int $minOrderTotal = null): static
    {
        return $this->state(fn (): array => [
            'type' => PriceRuleType::OrderPercentOff,
            'value' => $basisPoints,
            'min_order_total' => $minOrderTotal,
        ]);
    }

    public function orderAmountOff(int $pesewas, ?int $minOrderTotal = null): static
    {
        return $this->state(fn (): array => [
            'type' => PriceRuleType::OrderAmountOff,
            'value' => $pesewas,
            'min_order_total' => $minOrderTotal,
        ]);
    }

    public function forProduct(int $productId): static
    {
        return $this->state(fn (): array => [
            'scope_type' => PriceRuleScopeType::Product,
            'scope_id' => $productId,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function stackable(): static
    {
        return $this->state(fn (): array => ['stackable' => true]);
    }
}
