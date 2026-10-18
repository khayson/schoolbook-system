<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_no' => $this->po_no,
            'supplier_id' => $this->supplier_id,
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'status' => $this->status?->value,
            'ordered_at' => $this->ordered_at,
            'expected_at' => $this->expected_at?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'subtotal' => $this->subtotal,
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
