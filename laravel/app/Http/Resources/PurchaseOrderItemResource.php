<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrderItem */
class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'product_id' => $this->product_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->quantity_received,
            'quantity_outstanding' => $this->quantityOutstanding(),
            'unit_cost' => $this->unit_cost,
            'line_total' => $this->quantity_ordered * $this->unit_cost,
        ];
    }
}
