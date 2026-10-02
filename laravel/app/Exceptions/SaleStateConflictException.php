<?php

namespace App\Exceptions;

use App\Models\Sale;

/**
 * The sale changed between the request loading it and the action locking it
 * (e.g. a draft was moved to another customer). Nothing was written; reload and retry.
 */
class SaleStateConflictException extends ApiDomainException
{
    public function __construct(Sale $sale)
    {
        parent::__construct(
            message: 'This sale changed while the request was being processed. Reload it and try again.',
            errorCode: 'sale_state_conflict',
            status: 409,
            details: [
                'sale_id' => $sale->id,
                'retry' => true,
            ],
        );
    }
}
