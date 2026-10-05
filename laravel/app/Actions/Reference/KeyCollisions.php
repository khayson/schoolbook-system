<?php

namespace App\Actions\Reference;

use RuntimeException;

/**
 * Internal to PublishReferenceEdition: aborts (rolls back) a publish whose accepted rows
 * would duplicate other live titles.
 */
final class KeyCollisions extends RuntimeException
{
    /**
     * @param  array<int, array{book_id: int, title: string}>  $rows
     */
    public function __construct(public readonly array $rows)
    {
        parent::__construct('Accepted rows would duplicate other titles.');
    }
}
