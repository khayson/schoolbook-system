<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')],
            'isbn' => ['nullable', 'string', 'max:255', Rule::unique('products', 'isbn')],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')],
            'title' => ['required', 'string', 'max:255'],
            'level_id' => ['required', 'integer', Rule::exists('levels', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'language_id' => ['required', 'integer', Rule::exists('languages', 'id')],
            'publisher_id' => ['nullable', 'integer', Rule::exists('publishers', 'id')],
            'edition' => ['nullable', 'string', 'max:255'],
            'cost_price' => ['required', 'integer', 'min:0'],
            'selling_price' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_on_hand' => ['prohibited'],
        ];
    }
}
