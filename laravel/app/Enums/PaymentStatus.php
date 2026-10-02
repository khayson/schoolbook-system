<?php

namespace App\Enums;

/**
 * A sale's payment status, derived from its allocations (spec 9.2).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    /**
     * paid when nothing is left to pay (including a zero-total sale), partial when some
     * money is applied, otherwise unpaid.
     */
    public static function derive(int $total, int $amountPaid): self
    {
        return match (true) {
            $amountPaid >= $total => self::Paid,
            $amountPaid > 0 => self::Partial,
            default => self::Unpaid,
        };
    }
}
