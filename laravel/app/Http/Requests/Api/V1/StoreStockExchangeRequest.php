<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\StockExchangeRules;
use App\Models\StockExchange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockExchangeRequest extends FormRequest
{
    use StockExchangeRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', StockExchange::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'counterparty_name' => ['required', 'string', 'max:255'],
            'settlement' => ['nullable', 'string', Rule::in(['cash', 'installment', 'pay_later'])],
            'notes' => ['nullable', 'string'],
            ...$this->exchangeItemRules(),
        ];
    }
}
