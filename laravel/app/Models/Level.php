<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['level_group_id', 'name', 'slug', 'sort_order'])]
class Level extends Model
{
    use SoftDeletes;

    public function levelGroup(): BelongsTo
    {
        return $this->belongsTo(LevelGroup::class);
    }
}
