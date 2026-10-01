<?php

namespace App\Exceptions;

class CreditLimitExceededException extends ApiDomainException
{
    public const OVERRIDE_FLAG = 'override_credit_limit';

    public function __construct(int $creditLimit, int $outstanding, int $saleTotal)
    {
        parent::__construct(
            message: 'This sale takes the customer over their credit limit. Resend with override_credit_limit to proceed.',
            errorCode: 'credit_limit_exceeded',
            status: 409,
            details: [
                'credit_limit' => $creditLimit,
                'outstanding' => $outstanding,
                'sale_total' => $saleTotal,
                'projected_balance' => $outstanding + $saleTotal,
                'override_flag' => self::OVERRIDE_FLAG,
            ],
        );
    }
}
