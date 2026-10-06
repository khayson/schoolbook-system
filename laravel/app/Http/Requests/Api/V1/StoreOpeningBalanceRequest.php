<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;

/** POST /customers/{id}/opening-balance: amount in pesewas, the debt's date and due date. */
class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('customer'));
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_PESEWAS],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
