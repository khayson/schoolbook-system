<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('confirm', $this->route('saleReturn')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
