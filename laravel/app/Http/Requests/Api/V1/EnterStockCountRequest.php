<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /stock/counts/{id}/items: counted quantities; null clears an entry. */
class EnterStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('stockCount'));
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:5000'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.counted_qty' => ['present', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
