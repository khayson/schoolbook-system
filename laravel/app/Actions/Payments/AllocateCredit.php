<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\LocksLedgerRows;
use App\DTOs\Ledger\CreditApplication;
use App\Exceptions\AllocationExceedsCreditException;
use App\Exceptions\NoCreditAvailableException;
use App\Models\Customer;
use App\Models\User;
use App\Services\AllocationLedger;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Applies a customer's credit (unallocated money on valid payments) to invoices
 * (spec 9.3): to the chosen sales, or oldest-due first. Credit is consumed FIFO from
 * the payments holding it (paid_at, then id).
 *
 * Locks: customer -> target sales (ascending id) -> credit payments (ascending id).
 */
class AllocateCredit
{
    use LocksLedgerRows;

    public function __construct(
        private readonly AllocationLedger $ledger,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  list<array{sale_id: int, amount: int}>|null  $allocations  null = oldest first
     */
    public function execute(User $user, Customer $customer, ?array $allocations = null): CreditApplication
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $customer, $allocations) {
            $locked = $this->lockPayingCustomer($customer->id);

            if ($locked->credit_balance <= 0) {
                throw new NoCreditAvailableException($locked);
            }

            if (! empty($allocations)) {
                $sales = $this->lockCustomerSalesById($locked, array_map(fn (array $a): int => (int) $a['sale_id'], $allocations));
                $payments = $this->lockCreditPaymentsFifo($locked);
                $plan = $this->planExplicit($locked, $sales, $allocations);

                $requested = array_sum($plan);
                if ($requested > $locked->credit_balance) {
                    throw new AllocationExceedsCreditException($locked->credit_balance, $requested);
                }
            } else {
                $sales = $this->lockOpenSalesOldestFirst($locked)->keyBy('id');
                $payments = $this->lockCreditPaymentsFifo($locked);
                $plan = $this->planOldestFirst($sales->values(), $locked->credit_balance);
            }

            $rows = [];
            foreach ($plan as $saleId => $amount) {
                array_push($rows, ...$this->ledger->applyCredit($locked, $payments, $sales[$saleId], $amount, $user));
            }

            return new CreditApplication($locked->fresh(), array_sum($plan), $rows);
        }));
    }
}
