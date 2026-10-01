<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\SaleRules;
use Illuminate\Foundation\Http\FormRequest;

class PricingPreviewRequest extends FormRequest
{
    use SaleRules;

    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', $this->activeCustomerRule()],
            'sale_date' => ['sometimes', 'date'],
            'items' => ['required', 'array', 'min:1'],
            ...$this->lineRules(),
        ];
    }
}
