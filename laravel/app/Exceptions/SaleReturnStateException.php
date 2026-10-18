<?php

namespace App\Exceptions;

class SaleReturnStateException extends ApiDomainException
{
    public function __construct(string $message, string $code = 'sale_return_conflict', int $status = 409, array $details = [])
    {
        parent::__construct(
            message: $message,
            errorCode: $code,
            status: $status,
            details: $details,
        );
    }
}
