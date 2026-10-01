<?php

namespace App\Exceptions;

use App\DTOs\Pricing\PricedOrder;

class PriceChangedException extends ApiDomainException
{
    public function __construct(private readonly PricedOrder $pricedOrder)
    {
        parent::__construct(
            message: 'Prices have changed since the sale was drafted. Review the new totals and confirm again.',
            errorCode: 'price_changed',
            status: 409,
            details: ['priced_order' => $pricedOrder->toArray()],
        );
    }

    public function pricedOrder(): PricedOrder
    {
        return $this->pricedOrder;
    }
}
