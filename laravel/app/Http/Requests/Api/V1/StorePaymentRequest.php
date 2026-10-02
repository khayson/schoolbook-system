<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    /**
     * Inactive customers may still pay what they owe; soft-deleted ones are gone.
     * Reference is the trace to the real transaction: required for every non-cash method.
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_PESEWAS],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => $this->input('method') !== PaymentMethod::Cash->value)],
            'paid_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_allocate' => ['sometimes', 'boolean'],
            'allocations' => ['sometimes', 'nullable', 'array'],
            'allocations.*.sale_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_PESEWAS],
        ];
    }
}
