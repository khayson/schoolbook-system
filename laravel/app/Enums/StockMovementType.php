<?php

namespace App\Enums;

enum StockMovementType: string
{
    case ReceiptIn = 'receipt_in';
    case SaleOut = 'sale_out';
    case SaleVoidIn = 'sale_void_in';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';
    case Adjustment = 'adjustment';
    case Damage = 'damage';
    case CountAdjustment = 'count_adjustment';
}
