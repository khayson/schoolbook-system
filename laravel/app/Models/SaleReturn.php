<?php

namespace App\Models;

use App\Enums\SaleReturnStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable([
    'return_no',
    'sale_id',
    'customer_id',
    'status',
    'reason',
    'subtotal',
    'tax_total',
    'total',
    'created_by',
    'confirmed_at',
    'voided_at',
    'void_reason',
])]
class SaleReturn extends Model
{
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Sale returns cannot be deleted. Void instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SaleReturnStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function isDraft(): bool
    {
        return $this->status === SaleReturnStatus::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SaleReturnStatus::Confirmed;
    }
}
