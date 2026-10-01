<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait SaleRules
{
    /**
     * Soft-deleted and inactive rows are rejected, not just missing ones.
     */
    protected function activeCustomerRule(): Exists
    {
        return Rule::exists('customers', 'id')->whereNull('deleted_at')->where('is_active', true);
    }

    protected function activeProductRule(): Exists
    {
        return Rule::exists('products', 'id')->whereNull('deleted_at')->where('is_active', true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function lineRules(string $presence = 'required'): array
    {
        return [
            'items.*.product_id' => [$presence, 'integer', $this->activeProductRule()],
            'items.*.quantity' => [$presence, 'integer', 'min:1'],
            'items.*.override_unit_price' => ['nullable', 'integer', 'min:0'],
            'items.*.override_reason' => ['nullable', 'required_with:items.*.override_unit_price', 'string', 'max:255'],
        ];
    }
}
