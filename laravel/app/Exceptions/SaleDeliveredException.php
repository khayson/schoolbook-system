<?php

namespace App\Exceptions;

use App\Models\Sale;

/**
 * A delivered sale's books have physically left; voiding would put them back in
 * stock. Refused until returns exist (Phase 5).
 */
class SaleDeliveredException extends ApiDomainException
{
    public function __construct(Sale $sale)
    {
        parent::__construct(
            message: 'This sale has been delivered and cannot be voided. Record a return instead (available in a later release).',
            errorCode: 'sale_delivered',
            status: 409,
            details: [
                'sale_id' => $sale->id,
                'delivered_at' => $sale->delivered_at?->toIso8601String(),
            ],
        );
    }
}
