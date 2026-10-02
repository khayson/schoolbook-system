<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;

class ApplyCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('applyCredit', $this->route('customer')) ?? false;
    }

    /**
     * No allocations = oldest-due first.
     */
    public function rules(): array
    {
        return [
            'allocations' => ['sometimes', 'nullable', 'array'],
            'allocations.*.sale_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_PESEWAS],
        ];
    }
}
