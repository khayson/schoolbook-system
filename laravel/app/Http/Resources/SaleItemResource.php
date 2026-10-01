<?php

namespace App\Http\Resources;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleItem */
class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_id' => $this->sale_id,
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'product_title' => $this->product_title,
            'quantity' => $this->quantity,
            'base_price' => $this->base_price,
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'line_total' => $this->line_total,
            'unit_cost' => $this->unit_cost,
            'applied_rules' => $this->applied_rules,
            'is_price_overridden' => $this->is_price_overridden,
            'override_reason' => $this->override_reason,
        ];
    }
}
