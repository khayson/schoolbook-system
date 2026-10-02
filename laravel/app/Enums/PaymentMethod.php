<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Momo = 'momo';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    /**
     * Non-cash payments must carry the reference that traces back to the real transaction.
     */
    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }
}
