<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ReferenceBook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReferenceBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ReferenceBook::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'level_id' => ['sometimes', 'integer'],
            'subject_id' => ['sometimes', 'integer'],
            'language_id' => ['sometimes', 'integer'],
            'publisher_id' => ['sometimes', 'integer'],
            'category' => ['sometimes', Rule::in(ReferenceBook::CATEGORIES)],
            'stocked' => ['sometimes', Rule::in(['0', '1', 0, 1, true, false, 'true', 'false'])],
            'status' => ['sometimes', Rule::in(['approved', 'withdrawn'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
