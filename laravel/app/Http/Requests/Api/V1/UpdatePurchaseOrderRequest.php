<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('purchaseOrder')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', 'exists:suppliers,id'],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity_ordered' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items', 'integer', 'min:0'],
        ];
    }
}
