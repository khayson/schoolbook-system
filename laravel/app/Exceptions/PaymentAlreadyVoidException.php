<?php

namespace App\Exceptions;

use App\Models\Payment;

/**
 * The payment is void: it cannot be voided again or printed as a receipt.
 */
class PaymentAlreadyVoidException extends ApiDomainException
{
    public function __construct(Payment $payment, string $action = 'void')
    {
        parent::__construct(
            message: "Payment {$payment->receipt_no} is void.",
            errorCode: 'payment_already_void',
            status: 409,
            details: [
                'payment_id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'action' => $action,
            ],
        );
    }
}
