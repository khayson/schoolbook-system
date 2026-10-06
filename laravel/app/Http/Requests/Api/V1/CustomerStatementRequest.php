<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /customers/{id}/statement?from=&to=&format=json|pdf (owner; dates Africa/Accra, inclusive). */
class CustomerStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('customer'));
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', Rule::in(['json', 'pdf'])],
        ];
    }
}
