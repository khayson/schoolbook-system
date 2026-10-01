<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('customer')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(CustomerType::class)],
            'region' => ['sometimes', Rule::enum(GhanaRegion::class)],
            'district' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'credit_limit' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'code' => ['prohibited'],
            'credit_balance' => ['prohibited'],
        ];
    }
}
