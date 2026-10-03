<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'contact_person', 'phone', 'email', 'notes'])]
class Publisher extends Model
{
    use SoftDeletes;

    public function aliases(): HasMany
    {
        return $this->hasMany(PublisherAlias::class);
    }
}
