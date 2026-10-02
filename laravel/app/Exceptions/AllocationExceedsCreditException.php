<?php

namespace App\Exceptions;

class AllocationExceedsCreditException extends ApiDomainException
{
    public function __construct(int $creditBalance, int $requested)
    {
        parent::__construct(
            message: 'The allocations add up to more than the customer\'s available credit.',
            errorCode: 'allocation_exceeds_credit',
            status: 422,
            details: [
                'credit_balance' => $creditBalance,
                'requested' => $requested,
            ],
        );
    }
}
