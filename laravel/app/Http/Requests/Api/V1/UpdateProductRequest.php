<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return $product && $this->user()?->can('update', $product);
    }

    public function rules(): array
    {
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'sku' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($product->id)],
            'isbn' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'isbn')->ignore($product->id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'barcode')->ignore($product->id)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'level_id' => ['sometimes', 'required', 'integer', Rule::exists('levels', 'id')],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')],
            'language_id' => ['sometimes', 'required', 'integer', Rule::exists('languages', 'id')],
            'publisher_id' => ['sometimes', 'nullable', 'integer', Rule::exists('publishers', 'id')],
            'edition' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'selling_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_on_hand' => ['prohibited'],
        ];
    }
}
