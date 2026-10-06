<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_count_id',
    'product_id',
    'system_qty',
    'counted_qty',
    'variance',
    'baseline_movement_id',
    'counted_at',
    'unit_cost',
])]
class StockCountItem extends Model
{
    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
            'variance' => 'integer',
            'unit_cost' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function baselineMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'baseline_movement_id');
    }
}
