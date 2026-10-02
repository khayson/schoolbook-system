<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One row of the allocation ledger (spec 6.6). Immutable: corrections are reversal
 * rows (negated amount, reversal_of_id set), never edits or deletes.
 */
#[Fillable([
    'payment_id',
    'sale_id',
    'amount',
    'reversal_of_id',
    'created_by',
])]
class PaymentAllocation extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Payment allocations are immutable and cannot be updated. Insert a reversal row.');
        });

        static::deleting(function () {
            throw new LogicException('Payment allocations are immutable and cannot be deleted. Insert a reversal row.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The original row this reversal cancels.
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The reversal row that cancels this one, if any.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }
}
