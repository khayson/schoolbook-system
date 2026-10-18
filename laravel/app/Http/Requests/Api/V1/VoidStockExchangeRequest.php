<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VoidStockExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('stockExchange')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
