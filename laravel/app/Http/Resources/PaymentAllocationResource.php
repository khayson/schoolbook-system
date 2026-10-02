<?php

namespace App\Http\Resources;

use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAllocation */
class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'receipt_no' => $this->whenLoaded('payment', fn () => $this->payment->receipt_no),
            'sale_id' => $this->sale_id,
            'invoice_no' => $this->whenLoaded('sale', fn () => $this->sale->invoice_no),
            'amount' => $this->amount,
            'reversal_of_id' => $this->reversal_of_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
