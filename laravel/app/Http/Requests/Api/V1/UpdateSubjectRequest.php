<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Subject|null $subject */
        $subject = $this->route('subject');

        return $subject && $this->user()?->can('update', $subject);
    }

    public function rules(): array
    {
        /** @var Subject $subject */
        $subject = $this->route('subject');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('subjects', 'slug')->ignore($subject->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
