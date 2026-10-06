<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Sale */
class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'status' => $this->status?->value,
            'payment_status' => $this->payment_status?->value,
            'source' => $this->source?->value,
            'is_opening_balance' => (bool) $this->is_opening_balance,
            'sale_date' => $this->sale_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balance_due,
            'delivered_at' => $this->delivered_at,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'voided_by' => $this->voided_by,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
