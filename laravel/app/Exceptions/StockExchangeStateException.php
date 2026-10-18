<?php

namespace App\Exceptions;

class StockExchangeStateException extends ApiDomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, string $errorCode, int $status = 409, array $details = [])
    {
        parent::__construct(
            message: $message,
            errorCode: $errorCode,
            status: $status,
            details: $details,
        );
    }
}
