<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /school-directory/{id}/customer: optional contact details, or link an existing customer. */
class AddDirectorySchoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('addAsCustomer', $this->route('directorySchool'));
    }

    public function rules(): array
    {
        return [
            'link_customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'credit_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
