<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * unallocated_amount is a cached money field (amount minus the sum of this payment's
 * allocation rows): deliberately not fillable, set explicitly by actions only.
 */
#[Fillable([
    'receipt_no',
    'customer_id',
    'amount',
    'method',
    'reference',
    'paid_at',
    'status',
    'void_reason',
    'voided_at',
    'voided_by',
    'notes',
    'received_by',
])]
class Payment extends Model
{
    use LogsActivity;

    /**
     * Financial records are never deleted (spec rule 5). Payments are voided.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Payments are financial records and cannot be deleted. Void instead.');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'receipt_no',
                'customer_id',
                'amount',
                'method',
                'reference',
                'paid_at',
                'unallocated_amount',
                'status',
                'void_reason',
                'voided_at',
                'voided_by',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'unallocated_amount' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentRecordStatus::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isValid(): bool
    {
        return $this->status === PaymentRecordStatus::Valid;
    }
}
