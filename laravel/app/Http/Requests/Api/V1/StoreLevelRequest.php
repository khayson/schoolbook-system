<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Level::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'level_group_id' => ['required', 'integer', 'exists:level_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('levels', 'slug')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
