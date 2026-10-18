<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PriceRuleRules;
use App\Models\PriceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePriceRuleRequest extends FormRequest
{
    use PriceRuleRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', PriceRule::class) ?? false;
    }

    public function rules(): array
    {
        return $this->priceRuleFieldRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->priceRuleConsistencyErrors($this->all()) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
