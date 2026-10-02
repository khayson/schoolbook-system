<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Exceptions\SaleNotEditableException;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * amount_paid and balance_due are cached money fields: deliberately not fillable,
 * set explicitly by actions only.
 */
#[Fillable([
    'invoice_no',
    'customer_id',
    'status',
    'payment_status',
    'source',
    'sale_date',
    'due_date',
    'subtotal',
    'discount_total',
    'tax_total',
    'total',
    'delivered_at',
    'notes',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'cancelled_at',
    'cancel_reason',
    'voided_at',
    'voided_by',
    'void_reason',
])]
class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory, LogsActivity;

    /**
     * Financial records are never deleted (spec rule 5). Drafts are cancelled, confirmed sales voided.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Sales are financial records and cannot be deleted. Cancel or void instead.');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'invoice_no',
                'customer_id',
                'status',
                'payment_status',
                'sale_date',
                'due_date',
                'subtotal',
                'discount_total',
                'tax_total',
                'total',
                'amount_paid',
                'balance_due',
                'confirmed_by',
                'confirmed_at',
                'cancelled_at',
                'cancel_reason',
                'voided_by',
                'voided_at',
                'void_reason',
                'delivered_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_status' => PaymentStatus::class,
            'source' => SaleSource::class,
            'sale_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'balance_due' => 'integer',
            'delivered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * The allocation ledger for this sale; amount_paid is the sum of these rows.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isDraft(): bool
    {
        return $this->status === SaleStatus::Draft;
    }

    /**
     * Every status change goes through here so illegal moves fail in one place.
     *
     * @throws SaleNotEditableException
     */
    public function assertCanTransitionTo(SaleStatus $to, string $action): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new SaleNotEditableException($this, $action);
        }
    }
}
