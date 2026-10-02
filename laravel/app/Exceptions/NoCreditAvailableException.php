<?php

namespace App\Exceptions;

use App\Models\Customer;

class NoCreditAvailableException extends ApiDomainException
{
    public function __construct(Customer $customer)
    {
        parent::__construct(
            message: 'This customer has no credit to apply.',
            errorCode: 'no_credit_available',
            status: 409,
            details: [
                'customer_id' => $customer->id,
                'credit_balance' => $customer->credit_balance,
            ],
        );
    }
}
