<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\SaleRules;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;

class StoreDraftSaleRequest extends FormRequest
{
    use SaleRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Sale::class) ?? false;
    }

    /**
     * No `source` field: the staff API always creates staff sales (set server-side).
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', $this->activeCustomerRule()],
            'sale_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            ...$this->lineRules(),
        ];
    }
}
