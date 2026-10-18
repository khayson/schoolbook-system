<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('receive', $this->route('purchaseOrder')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
