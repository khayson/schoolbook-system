<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A stock-take: open → applied | cancelled (spec 6.3; docs/acceptance-phase3.md 3.10).
 */
#[Fillable([
    'reference',
    'status',
    'filters',
    'notes',
    'counted_by',
    'applied_at',
    'applied_by',
    'cancelled_at',
    'cancelled_by',
])]
class StockCount extends Model
{
    public const OPEN = 'open';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
