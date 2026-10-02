<?php

namespace App\Exceptions;

use App\Models\Payment;

/**
 * The method + reference (MoMo transaction id, bank reference, cheque number) is already
 * on a valid payment. Recording it again would count the same money twice. Voiding the
 * existing payment releases the reference.
 */
class DuplicatePaymentReferenceException extends ApiDomainException
{
    public function __construct(string $method, string $reference, ?Payment $existing)
    {
        $receipt = $existing?->receipt_no ?? 'another payment';

        parent::__construct(
            message: "This {$method} reference is already recorded on {$receipt}.",
            errorCode: 'duplicate_reference',
            status: 409,
            details: [
                'method' => $method,
                'reference' => $reference,
                'existing_payment_id' => $existing?->id,
                'existing_receipt_no' => $existing?->receipt_no,
                'existing_customer_id' => $existing?->customer_id,
                'existing_amount' => $existing?->amount,
                'existing_paid_at' => $existing?->paid_at?->toIso8601String(),
            ],
        );
    }
}
