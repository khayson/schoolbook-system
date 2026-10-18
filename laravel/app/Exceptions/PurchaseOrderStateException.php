<?php

namespace App\Exceptions;

class PurchaseOrderStateException extends ApiDomainException
{
    public function __construct(string $message, string $code = 'purchase_order_conflict', int $status = 409, array $details = [])
    {
        parent::__construct(
            message: $message,
            errorCode: $code,
            status: $status,
            details: $details,
        );
    }
}
