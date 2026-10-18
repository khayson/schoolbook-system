<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmStockExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('confirm', $this->route('stockExchange')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'settlement' => ['sometimes', 'string', Rule::in(['cash', 'installment', 'pay_later'])],
            'due_date' => ['nullable', 'date'],
            'override_credit_limit' => ['sometimes', 'boolean'],
            'apply_credit' => ['sometimes', 'boolean'],
        ];
    }
}
