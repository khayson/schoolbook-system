<?php

namespace App\Models;

use App\Enums\StockExchangeDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_exchange_id',
    'direction',
    'product_id',
    'quantity',
    'unit_cost',
    'unit_price',
])]
class StockExchangeItem extends Model
{
    protected function casts(): array
    {
        return [
            'direction' => StockExchangeDirection::class,
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    public function stockExchange(): BelongsTo
    {
        return $this->belongsTo(StockExchange::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
