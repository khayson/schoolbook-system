<?php

namespace App\Enums;

enum SaleReturnStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Void = 'void';
}
