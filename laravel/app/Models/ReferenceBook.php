<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A title on the approved (NaCCA) list. Never deleted: a title dropped from a later
 * edition becomes "withdrawn" and products pointing to it keep working.
 */
class ReferenceBook extends Model
{
    public const CATEGORIES = ['textbook', 'subject_supplement', 'reader', 'guidance', 'elearning'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [];
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

    public function firstSeenEdition(): BelongsTo
    {
        return $this->belongsTo(ReferenceEdition::class, 'first_seen_edition_id');
    }

    public function lastSeenEdition(): BelongsTo
    {
        return $this->belongsTo(ReferenceEdition::class, 'last_seen_edition_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'textbook' => 'Textbook',
            'subject_supplement' => 'Subject supplement',
            'reader' => 'Reader',
            'guidance' => 'Guidance and counselling',
            'elearning' => 'E-learning / other',
            default => $category,
        };
    }
}
