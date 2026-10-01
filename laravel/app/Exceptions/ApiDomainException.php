<?php

namespace App\Exceptions;

use DomainException;

/**
 * Base for business-rule failures that reach API clients.
 *
 * Rendered in bootstrap/app.php as the standard envelope:
 * { message, code, errors: {field: [..]}, details?: {...} }
 */
class ApiDomainException extends DomainException
{
    /**
     * @param  array<string, mixed>  $details  Extra machine-readable payload for the client.
     * @param  array<string, list<string>>  $errors  Field errors, same shape as validation errors.
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $details = [],
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        $envelope = [
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->errors === [] ? (object) [] : $this->errors,
        ];

        if ($this->details !== []) {
            $envelope['details'] = $this->details;
        }

        return $envelope;
    }
}
