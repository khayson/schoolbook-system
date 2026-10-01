<?php

namespace App\Exceptions;

class InsufficientStockException extends ApiDomainException
{
    /**
     * @param  list<array{product_id: int, sku: string, title: string, requested: int, available: int}>  $items
     */
    public function __construct(private readonly array $items)
    {
        parent::__construct(
            message: 'Insufficient stock for one or more products.',
            errorCode: 'insufficient_stock',
            status: 422,
            details: ['items' => $items],
        );
    }

    /**
     * @return list<array{product_id: int, sku: string, title: string, requested: int, available: int}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
