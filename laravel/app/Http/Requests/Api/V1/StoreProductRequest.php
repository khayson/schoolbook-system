<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Catalog\PrefillFromReferenceBook;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * With reference_book_id, title/level/subject/language/publisher not sent are taken
     * from the approved title before validation (explicit values win).
     */
    protected function prepareForValidation(): void
    {
        $this->replace(PrefillFromReferenceBook::apply($this->all()));
    }

    public function rules(): array
    {
        return [
            // Generated (BK-000123) when not sent.
            'sku' => ['nullable', 'string', 'max:255', Rule::unique('products', 'sku')],
            'isbn' => ['nullable', 'string', 'max:255', Rule::unique('products', 'isbn')],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('products', 'barcode')],
            'reference_book_id' => ['nullable', 'integer', Rule::exists('reference_books', 'id')],
            'variant_label' => ['nullable', 'string', 'max:100'],
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
            // Received through the stock ledger at the cost price.
            'opening_stock' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'stock_on_hand' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        if (! $this->filled('reference_book_id')) {
            return [];
        }

        return [
            'level_id.required' => 'This approved title is listed for a range of classes, not one level. Choose the level.',
            'subject_id.required' => 'This approved title has no subject on the list. Choose the subject.',
            'language_id.required' => 'The language of this approved title is not known. Choose the language.',
        ];
    }
}
