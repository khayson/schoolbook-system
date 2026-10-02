<?php

namespace App\Enums;

/**
 * Status of a payment record (valid/void). Not to be confused with PaymentStatus,
 * which is a sale's derived unpaid/partial/paid.
 */
enum PaymentRecordStatus: string
{
    case Valid = 'valid';
    case Void = 'void';
}
