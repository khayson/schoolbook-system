<?php

namespace App\Models;

use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

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
    /**
     * Snapshots are fixed once the sale leaves draft (spec rule 6).
     *
     * The parent status is read from the database, not from a loaded relation, so a
     * stale in-memory Sale cannot unlock its items. ConfirmSale rewrites the
     * verified prices while the sale row is still draft (under its lock), then
     * flips the status.
     *
     * Bulk query deletes (UpdateDraftSale's items()->delete()) bypass model events;
     * that path only runs after the draft status is checked under the sale lock.
     */
    protected static function booted(): void
    {
        $guard = function (SaleItem $item, string $verb): void {
            $status = Sale::query()->toBase()->where('id', $item->sale_id)->value('status');

            if ($status !== SaleStatus::Draft->value) {
                throw new LogicException("Sale items cannot be {$verb} once the sale is {$status}.");
            }
        };

        static::creating(fn (SaleItem $item) => $guard($item, 'added'));
        static::updating(fn (SaleItem $item) => $guard($item, 'changed'));
        static::deleting(fn (SaleItem $item) => $guard($item, 'deleted'));
    }

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
