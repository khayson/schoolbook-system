<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_id',
    'product_id',
    'product_title',
    'quantity',
    'base_price',
    'unit_price',
    'discount_amount',
    'tax_amount',
    'line_total',
    'unit_cost',
    'applied_rules',
    'is_price_overridden',
    'override_reason',
])]
class SaleItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'base_price' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'line_total' => 'integer',
            'unit_cost' => 'integer',
            'applied_rules' => 'array',
            'is_price_overridden' => 'boolean',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
