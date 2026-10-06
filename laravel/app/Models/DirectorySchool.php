<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school in the directory (docs/school-directory.md). Source data © OpenStreetMap
 * contributors, ODbL.
 */
#[Fillable([
    'source',
    'source_ref',
    'name',
    'search_name',
    'region',
    'district',
    'town',
    'phone',
    'levels',
    'ownership',
    'latitude',
    'longitude',
    'customer_id',
    'withdrawn_at',
])]
class DirectorySchool extends Model
{
    public const ATTRIBUTION = 'School directory data © OpenStreetMap contributors, available under the Open Database License (ODbL).';

    public const LEVELS = ['kindergarten' => 'Creche/KG', 'primary' => 'Primary', 'jhs' => 'JHS', 'shs' => 'SHS'];

    protected function casts(): array
    {
        return [
            'withdrawn_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @param  Builder<DirectorySchool>  $query */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull('withdrawn_at');
    }

    /** @return list<string> */
    public function levelList(): array
    {
        return $this->levels === null || $this->levels === '' ? [] : explode(',', $this->levels);
    }

    public function levelLabel(): ?string
    {
        $labels = array_map(fn (string $l) => self::LEVELS[$l] ?? $l, $this->levelList());

        return $labels === [] ? null : implode(', ', $labels);
    }
}
