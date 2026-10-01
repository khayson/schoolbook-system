<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Language|null $language */
        $language = $this->route('language');

        return $language && $this->user()?->can('update', $language);
    }

    public function rules(): array
    {
        /** @var Language $language */
        $language = $this->route('language');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('languages', 'code')->ignore($language->id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
