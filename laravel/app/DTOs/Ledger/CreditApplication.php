<?php

namespace App\DTOs\Ledger;

use App\Models\Customer;
use App\Models\PaymentAllocation;

readonly class CreditApplication
{
    /**
     * @param  list<PaymentAllocation>  $allocations
     */
    public function __construct(
        public Customer $customer,
        public int $appliedTotal,
        public array $allocations,
    ) {}
}
