<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VoidSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('saleReturn')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
