<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\StockExchangeRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockExchangeRequest extends FormRequest
{
    use StockExchangeRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('stockExchange')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer', 'exists:customers,id'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
            'counterparty_name' => ['sometimes', 'string', 'max:255'],
            'settlement' => ['sometimes', 'nullable', 'string', Rule::in(['cash', 'installment', 'pay_later'])],
            'notes' => ['sometimes', 'nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.direction' => ['required_with:items', 'string', 'in:in,out'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
