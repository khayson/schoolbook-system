<?php

namespace App\Exceptions;

use App\Models\Sale;

class AllocationExceedsBalanceException extends ApiDomainException
{
    public function __construct(Sale $sale, int $requested)
    {
        parent::__construct(
            message: "The amount for {$sale->invoice_no} is more than its balance due.",
            errorCode: 'allocation_exceeds_balance',
            status: 422,
            details: [
                'sale_id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'balance_due' => $sale->balance_due,
                'requested' => $requested,
            ],
        );
    }
}
