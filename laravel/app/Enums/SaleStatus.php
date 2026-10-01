<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Void = 'void';
}
