<?php

namespace App\Exceptions;

/**
 * Service-level second line of defence behind Form Requests.
 * Bad input that slips past validation still becomes a 422, never a 500.
 */
class InvalidInputException extends ApiDomainException
{
    public function __construct(string $field, string $message)
    {
        parent::__construct(
            message: $message,
            errorCode: 'validation_failed',
            status: 422,
            errors: [$field => [$message]],
        );
    }
}
