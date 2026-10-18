<?php

namespace App\Enums;

enum StockExchangeStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Void = 'void';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
