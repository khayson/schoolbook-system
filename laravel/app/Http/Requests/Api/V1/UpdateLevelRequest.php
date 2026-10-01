<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Level|null $level */
        $level = $this->route('level');

        return $level && $this->user()?->can('update', $level);
    }

    public function rules(): array
    {
        /** @var Level $level */
        $level = $this->route('level');

        return [
            'level_group_id' => ['sometimes', 'required', 'integer', 'exists:level_groups,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('levels', 'slug')->ignore($level->id)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
