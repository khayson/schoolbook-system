<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /stock/counts: all active products, or a level/subject/language/publisher filter. */
class StoreStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', StockCount::class);
    }

    public function rules(): array
    {
        return [
            'filters' => ['sometimes', 'nullable', 'array:level_id,subject_id,language_id,publisher_id'],
            'filters.level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('levels', 'id')],
            'filters.subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')],
            'filters.language_id' => ['sometimes', 'nullable', 'integer', Rule::exists('languages', 'id')],
            'filters.publisher_id' => ['sometimes', 'nullable', 'integer', Rule::exists('publishers', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
