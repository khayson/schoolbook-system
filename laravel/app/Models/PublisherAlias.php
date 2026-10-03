<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A spelling of a publisher's name as printed in the approved list, e.g.
 * "Hibiscus Books Limited" for the publisher "Hibiscus Books Ltd".
 */
class PublisherAlias extends Model
{
    protected $guarded = ['*'];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}
