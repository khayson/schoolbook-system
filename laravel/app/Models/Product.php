<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'sku',
    'isbn',
    'barcode',
    'title',
    'level_id',
    'subject_id',
    'language_id',
    'publisher_id',
    'reference_book_id',
    'variant_label',
    'edition',
    'cost_price',
    'selling_price',
    'reorder_level',
    'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return [
            'cost_price' => 'integer',
            'selling_price' => 'integer',
            'reorder_level' => 'integer',
            'stock_on_hand' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'cost_price',
                'selling_price',
                'sku',
                'title',
                'reorder_level',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** The approved-list title this product is (null: not on the list). */
    public function referenceBook(): BelongsTo
    {
        return $this->belongsTo(ReferenceBook::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
