<?php

namespace App\Http\Resources;

use App\Models\SaleReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturnItem */
class SaleReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_return_id' => $this->sale_return_id,
            'sale_item_id' => $this->sale_item_id,
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'restock_condition' => $this->restock_condition?->value,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
