<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'integer'],
            'method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'status' => ['sometimes', Rule::enum(PaymentRecordStatus::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
