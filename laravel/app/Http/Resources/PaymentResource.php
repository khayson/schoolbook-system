<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'customer_id' => $this->customer_id,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'amount' => $this->amount,
            'method' => $this->method?->value,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at,
            'unallocated_amount' => $this->unallocated_amount,
            'status' => $this->status?->value,
            'void_reason' => $this->void_reason,
            'voided_at' => $this->voided_at,
            'voided_by' => $this->voided_by,
            'notes' => $this->notes,
            'received_by' => $this->received_by,
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
