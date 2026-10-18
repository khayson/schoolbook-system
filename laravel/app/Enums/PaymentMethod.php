<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Momo = 'momo';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case ReturnCredit = 'return_credit';

    /**
     * Non-cash payments must carry the reference that traces back to the real transaction.
     * return_credit stores the SRN for audit but is not a customer-facing transfer reference.
     */
    public function requiresReference(): bool
    {
        return ! in_array($this, [self::Cash, self::ReturnCredit], true);
    }
}
