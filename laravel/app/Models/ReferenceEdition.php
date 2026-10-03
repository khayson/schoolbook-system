<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported edition of the approved list. Written only by the Reference actions.
 */
class ReferenceEdition extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_DISCARDED = 'discarded';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'activated_at' => 'datetime',
            'skipped' => 'array',
            'stated_counts' => 'array',
            'rows_total' => 'integer',
            'rows_new' => 'integer',
            'rows_unchanged' => 'integer',
            'rows_changed' => 'integer',
            'rows_removed' => 'integer',
            'rows_with_issues' => 'integer',
            'rows_skipped' => 'integer',
        ];
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(ReferenceImportRow::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public static function active(): ?self
    {
        return static::query()->where('status', self::STATUS_ACTIVE)->latest('id')->first();
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
