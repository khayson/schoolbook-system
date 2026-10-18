<?php

namespace App\Http\Resources;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleReturn */
class SaleReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_no' => $this->return_no,
            'sale_id' => $this->sale_id,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
            'status' => $this->status?->value,
            'reason' => $this->reason,
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'created_by' => $this->created_by,
            'confirmed_at' => $this->confirmed_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'items' => SaleReturnItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
