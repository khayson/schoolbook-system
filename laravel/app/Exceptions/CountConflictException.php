<?php

namespace App\Exceptions;

/**
 * Applying the count would leave stock below zero while allow_negative_stock is off.
 * Nothing was applied.
 */
class CountConflictException extends ApiDomainException
{
    /**
     * @param  list<array{product_id: int, sku: string, title: string, stock_on_hand: int, variance: int, resulting: int}>  $items
     */
    public function __construct(array $items)
    {
        parent::__construct(
            message: 'Applying this count would make stock negative for one or more products. Nothing was applied.',
            errorCode: 'count_conflict',
            status: 409,
            details: ['items' => $items],
        );
    }
}
