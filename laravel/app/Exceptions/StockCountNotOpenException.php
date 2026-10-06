<?php

namespace App\Exceptions;

use App\Models\StockCount;

/** The count is applied or cancelled: no more entries, no second apply. */
class StockCountNotOpenException extends ApiDomainException
{
    public function __construct(StockCount $count, string $action)
    {
        parent::__construct(
            message: "Stock count {$count->reference} is {$count->status}; it can no longer be changed.",
            errorCode: 'stock_count_not_open',
            status: 409,
            details: ['stock_count_id' => $count->id, 'status' => $count->status, 'action' => $action],
        );
    }
}
