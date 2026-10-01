<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\SaleRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDraftSaleRequest extends FormRequest
{
    use SaleRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('sale')) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer', $this->activeCustomerRule()],
            'sale_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            ...$this->lineRules('required_with:items'),
        ];
    }
}
