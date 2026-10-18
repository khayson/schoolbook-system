<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RestockCondition;
use App\Models\SaleReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SaleReturn::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.restock_condition' => ['required', Rule::enum(RestockCondition::class)],
        ];
    }
}
