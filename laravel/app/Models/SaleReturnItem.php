<?php

namespace App\Models;

use App\Enums\RestockCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sale_return_id',
    'sale_item_id',
    'product_id',
    'quantity',
    'unit_price',
    'line_total',
    'restock_condition',
    'unit_cost',
])]
class SaleReturnItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'unit_cost' => 'integer',
            'restock_condition' => RestockCondition::class,
        ];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
