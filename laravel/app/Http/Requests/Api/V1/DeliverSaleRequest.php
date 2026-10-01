<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DeliverSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('deliver', $this->route('sale')) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
