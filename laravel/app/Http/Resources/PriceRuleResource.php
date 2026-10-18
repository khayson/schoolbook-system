<?php

namespace App\Http\Resources;

use App\Models\PriceRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceRule */
class PriceRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'scope_type' => $this->scope_type->value,
            'scope_id' => $this->scope_id,
            'customer_scope' => $this->customer_scope->value,
            'customer_scope_value' => $this->customer_scope_value,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'min_order_total' => $this->min_order_total,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'priority' => $this->priority,
            'stackable' => $this->stackable,
            'floor_policy' => $this->floor_policy->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
