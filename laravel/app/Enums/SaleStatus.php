<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Void = 'void';

    /**
     * The sale state machine (spec 9.1) in one place. Delivery is not a status.
     */
    public function canTransitionTo(self $to): bool
    {
        $allowed = match ($this) {
            self::Draft, self::Requested => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Void],
            self::Cancelled, self::Void => [],
        };

        return in_array($to, $allowed, true);
    }
}
