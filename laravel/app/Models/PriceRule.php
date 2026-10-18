<?php

namespace App\Models;

use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use Database\Factories\PriceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'name',
    'type',
    'value',
    'scope_type',
    'scope_id',
    'customer_scope',
    'customer_scope_value',
    'min_quantity',
    'max_quantity',
    'min_order_total',
    'starts_at',
    'ends_at',
    'priority',
    'stackable',
    'floor_policy',
    'is_active',
])]
class PriceRule extends Model
{
    /** @use HasFactory<PriceRuleFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'type' => PriceRuleType::class,
            'value' => 'integer',
            'scope_type' => PriceRuleScopeType::class,
            'scope_id' => 'integer',
            'customer_scope' => PriceRuleCustomerScope::class,
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'min_order_total' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'stackable' => 'boolean',
            'floor_policy' => PriceRuleFloorPolicy::class,
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** Combined product-scope + customer-scope specificity for sort ties. */
    public function specificity(): int
    {
        return $this->scope_type->specificity() + $this->customer_scope->specificity();
    }

    /**
     * @param  Builder<PriceRule>  $query
     * @return Builder<PriceRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rules whose validity window contains the given date (null bounds are open).
     *
     * @param  Builder<PriceRule>  $query
     * @return Builder<PriceRule>
     */
    public function scopeEffectiveOn(Builder $query, Carbon|string $date): Builder
    {
        $moment = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $query
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $moment);
            })
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment);
            });
    }

    /**
     * Line-level rule types (excludes order-level and stretch bonus_units).
     *
     * @param  Builder<PriceRule>  $query
     * @return Builder<PriceRule>
     */
    public function scopeLineLevel(Builder $query): Builder
    {
        return $query->whereIn('type', [
            PriceRuleType::PercentOff->value,
            PriceRuleType::AmountOff->value,
            PriceRuleType::FixedPrice->value,
        ]);
    }

    /**
     * Order-level rule types.
     *
     * @param  Builder<PriceRule>  $query
     * @return Builder<PriceRule>
     */
    public function scopeOrderLevel(Builder $query): Builder
    {
        return $query->whereIn('type', [
            PriceRuleType::OrderPercentOff->value,
            PriceRuleType::OrderAmountOff->value,
        ]);
    }
}
