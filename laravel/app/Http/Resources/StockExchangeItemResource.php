<?php

namespace App\Http\Resources;

use App\Models\StockExchangeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockExchangeItem */
class StockExchangeItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction?->value,
            'direction_label' => $this->direction?->label(),
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'unit_price' => $this->unit_price,
        ];
    }
}
