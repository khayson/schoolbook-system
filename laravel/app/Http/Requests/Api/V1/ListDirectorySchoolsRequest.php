<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DirectorySchool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /school-directory?search=&region=&district=&added=0|1 */
class ListDirectorySchoolsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', DirectorySchool::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'region' => ['sometimes', 'nullable', Rule::in(['Greater Accra', 'Central'])],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'added' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
