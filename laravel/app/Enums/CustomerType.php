<?php

namespace App\Enums;

enum CustomerType: string
{
    case School = 'school';
    case Reseller = 'reseller';
    case Individual = 'individual';
}
