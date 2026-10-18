<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function isOpenForReceiving(): bool
    {
        return in_array($this, [self::Ordered, self::Partial], true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
