<?php

namespace App\Models;

use App\Enums\StockExchangeSettlement;
use App\Enums\StockExchangeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'exchange_no',
    'customer_id',
    'supplier_id',
    'counterparty_name',
    'status',
    'notes',
    'settlement',
    'created_by',
    'sale_id',
    'goods_receipt_id',
    'confirmed_at',
    'voided_at',
    'void_reason',
])]
class StockExchange extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StockExchangeStatus::class,
            'settlement' => StockExchangeSettlement::class,
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockExchangeItem::class);
    }
}
