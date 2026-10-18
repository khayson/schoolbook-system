<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PriceRuleRules;
use App\Models\PriceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePriceRuleRequest extends FormRequest
{
    use PriceRuleRules;

    public function authorize(): bool
    {
        /** @var PriceRule $rule */
        $rule = $this->route('price_rule');

        return $this->user()?->can('update', $rule) ?? false;
    }

    public function rules(): array
    {
        return $this->priceRuleFieldRules(sometimes: true);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var PriceRule $existing */
            $existing = $this->route('price_rule');
            $merged = array_merge([
                'type' => $existing->type->value,
                'scope_type' => $existing->scope_type->value,
                'scope_id' => $existing->scope_id,
                'customer_scope' => $existing->customer_scope->value,
                'customer_scope_value' => $existing->customer_scope_value,
                'value' => $existing->value,
            ], $this->all());

            foreach ($this->priceRuleConsistencyErrors($merged) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
