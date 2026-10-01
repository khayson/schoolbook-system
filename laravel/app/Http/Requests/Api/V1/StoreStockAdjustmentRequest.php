<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\StockMovementType;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productId = $this->integer('product_id');

        if ($productId <= 0) {
            return false;
        }

        $product = Product::query()->find($productId);

        return $product !== null && ($this->user()?->can('update', $product) ?? false);
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['required', 'integer', Rule::notIn([0])],
            'type' => ['required', 'string', Rule::in([
                StockMovementType::Adjustment->value,
                StockMovementType::Damage->value,
            ])],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }
}
