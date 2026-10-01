<?php

namespace App\Exceptions;

use App\Models\Sale;

class SaleNotEditableException extends ApiDomainException
{
    public function __construct(Sale $sale, string $action = 'updated')
    {
        parent::__construct(
            message: "Only draft sales can be {$action}.",
            errorCode: 'sale_not_editable',
            status: 409,
            details: [
                'sale_id' => $sale->id,
                'status' => $sale->status->value,
            ],
        );
    }
}
