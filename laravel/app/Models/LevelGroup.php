<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'sort_order'])]
class LevelGroup extends Model
{
    public function levels(): HasMany
    {
        return $this->hasMany(Level::class);
    }
}
