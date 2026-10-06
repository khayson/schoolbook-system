<?php

namespace App\Exceptions;

use App\Models\Sale;

/** One live opening balance per customer; void the existing one to replace it. */
class OpeningBalanceExistsException extends ApiDomainException
{
    public function __construct(int $customerId, ?Sale $existing)
    {
        parent::__construct(
            message: 'This customer already has an opening balance'.($existing ? " ({$existing->invoice_no})" : '').'. Void it first to enter a different one.',
            errorCode: 'opening_balance_exists',
            status: 409,
            details: array_filter([
                'customer_id' => $customerId,
                'sale_id' => $existing?->id,
                'invoice_no' => $existing?->invoice_no,
            ], fn ($v) => $v !== null),
        );
    }
}
