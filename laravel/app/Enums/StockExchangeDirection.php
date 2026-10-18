<?php

namespace App\Enums;

enum StockExchangeDirection: string
{
    /** Taken: she received books from the counterparty. */
    case In = 'in';

    /** Not taken: counterparty took books from her (becomes a sale). */
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'They gave us (Taken)',
            self::Out => 'They took from us',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::In => 'Taken',
            self::Out => 'They took',
        };
    }
}
