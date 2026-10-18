<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait StockExchangeRules
{
    /** @return array<string, mixed> */
    protected function exchangeItemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.direction' => ['required', 'string', 'in:in,out'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
