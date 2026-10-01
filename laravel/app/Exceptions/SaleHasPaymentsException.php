<?php

namespace App\Exceptions;

use App\Models\Sale;

/**
 * Phase 2B: void is refused once money has been applied. Phase 2C extends
 * VoidSale to reverse allocations instead.
 */
class SaleHasPaymentsException extends ApiDomainException
{
    public function __construct(Sale $sale)
    {
        parent::__construct(
            message: 'This sale has payments applied and cannot be voided yet.',
            errorCode: 'sale_has_payments',
            status: 409,
            details: [
                'sale_id' => $sale->id,
                'amount_paid' => $sale->amount_paid,
            ],
        );
    }
}
