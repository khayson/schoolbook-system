<?php

namespace App\Enums;

enum StockExchangeSettlement: string
{
    /** They pay the full invoice now. */
    case Cash = 'cash';

    /** They pay in parts against the invoice. */
    case Installment = 'installment';

    /** Invoice stays open; pay later. */
    case PayLater = 'pay_later';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash and carry',
            self::Installment => 'Installment',
            self::PayLater => 'Pay later',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cash => 'Full payment when you confirm (open the sale and record cash/MoMo).',
            self::Installment => 'Confirm the invoice, then record part payments over time.',
            self::PayLater => 'Confirm the invoice with balance due; collect later.',
        };
    }
}
