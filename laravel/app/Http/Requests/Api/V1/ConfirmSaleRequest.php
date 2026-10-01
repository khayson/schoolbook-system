<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('confirm', $this->route('sale')) ?? false;
    }

    public function rules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
            'override_credit_limit' => ['sometimes', 'boolean'],
        ];
    }
}
