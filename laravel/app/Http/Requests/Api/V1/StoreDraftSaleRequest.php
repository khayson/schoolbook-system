<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;

class StoreDraftSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sale::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'sale_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'source' => ['sometimes', 'in:staff,portal'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.override_unit_price' => ['nullable', 'integer', 'min:0'],
            'items.*.override_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
