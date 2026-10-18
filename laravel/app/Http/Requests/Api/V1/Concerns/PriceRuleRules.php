<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use Illuminate\Validation\Rule;

trait PriceRuleRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function priceRuleFieldRules(bool $sometimes = false): array
    {
        $req = $sometimes ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255'],
            'type' => [$req, Rule::enum(PriceRuleType::class)],
            'value' => [$req, 'integer', 'min:0'],
            'scope_type' => [$req, Rule::enum(PriceRuleScopeType::class)],
            'scope_id' => ['nullable', 'integer', 'min:1'],
            'customer_scope' => [$req, Rule::enum(PriceRuleCustomerScope::class)],
            'customer_scope_value' => ['nullable', 'string', 'max:255'],
            'min_quantity' => ['nullable', 'integer', 'min:1'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'gte:min_quantity'],
            'min_order_total' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => [$sometimes ? 'sometimes' : 'nullable', 'integer'],
            'stackable' => [$sometimes ? 'sometimes' : 'nullable', 'boolean'],
            'floor_policy' => [$sometimes ? 'sometimes' : 'nullable', Rule::enum(PriceRuleFloorPolicy::class)],
            'is_active' => [$sometimes ? 'sometimes' : 'nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function priceRuleConsistencyErrors(array $data): array
    {
        $errors = [];

        $scopeType = isset($data['scope_type'])
            ? PriceRuleScopeType::tryFrom((string) $data['scope_type'])
            : null;
        if ($scopeType !== null && $scopeType !== PriceRuleScopeType::All && empty($data['scope_id'])) {
            $errors['scope_id'] = 'scope_id is required when scope_type is not all.';
        }
        if ($scopeType === PriceRuleScopeType::All && array_key_exists('scope_id', $data) && $data['scope_id'] !== null) {
            $errors['scope_id'] = 'scope_id must be null when scope_type is all.';
        }

        $customerScope = isset($data['customer_scope'])
            ? PriceRuleCustomerScope::tryFrom((string) $data['customer_scope'])
            : null;
        if ($customerScope !== null && $customerScope !== PriceRuleCustomerScope::All
            && (! array_key_exists('customer_scope_value', $data) || $data['customer_scope_value'] === null || $data['customer_scope_value'] === '')) {
            $errors['customer_scope_value'] = 'customer_scope_value is required when customer_scope is not all.';
        }

        if ($customerScope === PriceRuleCustomerScope::CustomerType
            && isset($data['customer_scope_value'])
            && CustomerType::tryFrom((string) $data['customer_scope_value']) === null) {
            $errors['customer_scope_value'] = 'customer_scope_value must be a valid customer type.';
        }

        if ($customerScope === PriceRuleCustomerScope::Region
            && isset($data['customer_scope_value'])
            && GhanaRegion::tryFrom((string) $data['customer_scope_value']) === null) {
            $errors['customer_scope_value'] = 'customer_scope_value must be a valid Ghana region.';
        }

        $type = isset($data['type']) ? PriceRuleType::tryFrom((string) $data['type']) : null;
        if ($type === PriceRuleType::PercentOff || $type === PriceRuleType::OrderPercentOff) {
            if (isset($data['value']) && (int) $data['value'] > 10000) {
                $errors['value'] = 'Percent rules use basis points; max 10000 (100%).';
            }
        }

        return $errors;
    }
}
