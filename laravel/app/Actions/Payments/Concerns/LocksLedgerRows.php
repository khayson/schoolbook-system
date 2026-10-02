<?php

namespace App\Actions\Payments\Concerns;

use App\Enums\PaymentRecordStatus;
use App\Enums\SaleStatus;
use App\Exceptions\AllocationExceedsBalanceException;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleNotPayableException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;

/**
 * Locking and allocation planning shared by the payment actions.
 *
 * Global lock order (spec 5.14): customer -> sales (ascending id) -> payments
 * (ascending id) -> sale items -> products -> number sequence last. All reads here are
 * locking reads, so they can precede the transaction's first plain read.
 */
trait LocksLedgerRows
{
    private function lockPayingCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();

        if ($customer === null) {
            throw new InvalidInputException('customer_id', 'The selected customer does not exist.');
        }

        return $customer;
    }

    /**
     * Locks only this customer's sales among the ids (ascending), so a foreign sale id in
     * a request never takes a lock outside the customer that is held.
     *
     * @param  list<int>  $saleIds
     * @return Collection<int, Sale> keyed by id
     */
    private function lockCustomerSalesById(Customer $customer, array $saleIds): Collection
    {
        $ids = array_values(array_unique($saleIds));
        sort($ids);

        return Sale::query()
            ->whereKey($ids)
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The customer's confirmed sales with something left to pay, locked in id order and
     * returned oldest-due first: due_date ascending (no due date last), sale_date, id.
     *
     * @return Collection<int, Sale>
     */
    private function lockOpenSalesOldestFirst(Customer $customer): Collection
    {
        return Sale::query()
            ->where('customer_id', $customer->id)
            ->where('status', SaleStatus::Confirmed)
            ->where('balance_due', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->sort(fn (Sale $a, Sale $b): int => [
                $a->due_date === null ? 1 : 0, $a->due_date?->toDateString(), $a->sale_date->toDateString(), $a->id,
            ] <=> [
                $b->due_date === null ? 1 : 0, $b->due_date?->toDateString(), $b->sale_date->toDateString(), $b->id,
            ])
            ->values();
    }

    /**
     * Valid payments holding unallocated credit, locked in id order and returned FIFO:
     * paid_at ascending, then id.
     *
     * @return Collection<int, Payment>
     */
    private function lockCreditPaymentsFifo(Customer $customer): Collection
    {
        return Payment::query()
            ->where('customer_id', $customer->id)
            ->where('status', PaymentRecordStatus::Valid)
            ->where('unallocated_amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->sort(fn (Payment $a, Payment $b): int => [$a->paid_at->getTimestamp(), $a->id] <=> [$b->paid_at->getTimestamp(), $b->id])
            ->values();
    }

    /**
     * Validates explicit allocations against the locked sales. Duplicate sale ids are summed.
     * Call only after every lock is taken (it may do a plain read).
     *
     * @param  list<array{sale_id: int|string, amount: int|string}>  $allocations
     * @param  Collection<int, Sale>  $sales  this customer's locked sales, keyed by id
     * @return array<int, int> sale_id => amount, in request order
     */
    private function planExplicit(Customer $customer, Collection $sales, array $allocations): array
    {
        $plan = [];
        foreach ($allocations as $index => $allocation) {
            $saleId = (int) $allocation['sale_id'];
            $amount = (int) $allocation['amount'];

            if ($amount <= 0) {
                throw new InvalidInputException("allocations.{$index}.amount", 'Each allocation amount must be greater than zero.');
            }

            $plan[$saleId] = ($plan[$saleId] ?? 0) + $amount;
        }

        foreach ($plan as $saleId => $amount) {
            $sale = $sales->get($saleId);

            if ($sale === null) {
                // Not among this customer's locked sales; a plain read (after all locks) says why.
                $reason = Sale::query()->whereKey($saleId)->exists()
                    ? SaleNotPayableException::OTHER_CUSTOMER
                    : SaleNotPayableException::NOT_FOUND;

                throw new SaleNotPayableException($saleId, $reason);
            }
            if ($sale->status !== SaleStatus::Confirmed) {
                throw new SaleNotPayableException($saleId, SaleNotPayableException::NOT_CONFIRMED, $sale->status->value);
            }
            if ($sale->balance_due === 0) {
                throw new SaleNotPayableException($saleId, SaleNotPayableException::FULLY_PAID);
            }
            if ($amount > $sale->balance_due) {
                throw new AllocationExceedsBalanceException($sale, $amount);
            }
        }

        return $plan;
    }

    /**
     * @param  Collection<int, Sale>  $openSales  oldest-due first
     * @return array<int, int> sale_id => amount
     */
    private function planOldestFirst(Collection $openSales, int $available): array
    {
        $plan = [];
        foreach ($openSales as $sale) {
            if ($available <= 0) {
                break;
            }
            $take = min($available, $sale->balance_due);
            $plan[$sale->id] = $take;
            $available -= $take;
        }

        return $plan;
    }
}
