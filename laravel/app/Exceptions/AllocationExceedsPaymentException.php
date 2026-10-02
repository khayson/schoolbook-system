<?php

namespace App\Exceptions;

class AllocationExceedsPaymentException extends ApiDomainException
{
    public function __construct(int $amount, int $allocated)
    {
        parent::__construct(
            message: 'The allocations add up to more than the payment amount.',
            errorCode: 'allocation_exceeds_payment',
            status: 422,
            details: [
                'amount' => $amount,
                'allocated_total' => $allocated,
            ],
        );
    }
}
