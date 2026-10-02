<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * The only code that writes payment_allocations (spec 6.6, 9.5).
 *
 * Model: a valid payment's unallocated_amount is a pool of the customer's credit.
 * allocate() moves money from that pool onto a sale; reverse() moves an earlier
 * allocation back into the pool with a negating ledger row. Each call updates every
 * cache it touches (sale amount_paid / balance_due / payment_status, the payment's
 * unallocated_amount, the customer's credit_balance / outstanding_balance), so the
 * invariants hold after every single call.
 *
 * Callers must already hold, in the global lock order, the customer, sale and payment
 * rows passed in, and must pass those same locked instances.
 */
class AllocationLedger
{
    public function allocate(Customer $customer, Payment $payment, Sale $sale, int $amount, User $user): PaymentAllocation
    {
        $this->assertSameCustomer($customer, $payment, $sale);

        if ($amount <= 0) {
            throw new LogicException('Allocation amount must be positive.');
        }
        if (! $payment->isValid()) {
            throw new LogicException("Payment {$payment->id} is void and cannot be allocated.");
        }
        if ($sale->status !== SaleStatus::Confirmed) {
            throw new LogicException("Sale {$sale->id} is not confirmed.");
        }
        if ($amount > $payment->unallocated_amount) {
            throw new LogicException("Allocation of {$amount} exceeds payment {$payment->id} unallocated {$payment->unallocated_amount}.");
        }
        if ($amount > $sale->balance_due) {
            throw new LogicException("Allocation of {$amount} exceeds sale {$sale->id} balance due {$sale->balance_due}.");
        }

        $row = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'amount' => $amount,
            'reversal_of_id' => null,
            'created_by' => $user->id,
        ]);

        $sale->amount_paid += $amount;
        $sale->balance_due -= $amount;
        $sale->payment_status = PaymentStatus::derive($sale->total, $sale->amount_paid);
        $sale->save();

        $payment->unallocated_amount -= $amount;
        $payment->save();

        $customer->credit_balance -= $amount;
        $customer->outstanding_balance -= $amount;
        $customer->save();

        return $row;
    }

    /**
     * Moves $amount of the customer's credit onto one sale, drawing from the given credit
     * payments in their order (callers pass them FIFO: paid_at, then id). The caller holds
     * the customer, the sale and those payments.
     *
     * @param  Collection<int, Payment>  $creditPayments
     * @return list<PaymentAllocation>
     */
    public function applyCredit(Customer $customer, Collection $creditPayments, Sale $sale, int $amount, User $user): array
    {
        $rows = [];
        foreach ($creditPayments as $payment) {
            if ($amount === 0) {
                break;
            }
            $take = min($amount, $payment->unallocated_amount);
            if ($take > 0) {
                $rows[] = $this->allocate($customer, $payment, $sale, $take, $user);
                $amount -= $take;
            }
        }

        if ($amount !== 0) {
            // credit_balance promised more than the payments hold: an invariant is broken.
            throw new LogicException("Customer {$customer->id} credit_balance does not match its payments' unallocated amounts.");
        }

        return $rows;
    }

    /**
     * Cancels one effective allocation: the money returns to the payment's pool and the
     * customer's credit; a confirmed sale owes it again.
     */
    public function reverse(Customer $customer, Payment $payment, Sale $sale, PaymentAllocation $original, User $user): PaymentAllocation
    {
        $this->assertSameCustomer($customer, $payment, $sale);

        if ($original->isReversal() || $original->payment_id !== $payment->id || $original->sale_id !== $sale->id) {
            throw new LogicException("Allocation {$original->id} cannot be reversed against payment {$payment->id} / sale {$sale->id}.");
        }

        // The unique index on reversal_of_id also refuses a second reversal.
        $row = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'sale_id' => $sale->id,
            'amount' => -$original->amount,
            'reversal_of_id' => $original->id,
            'created_by' => $user->id,
        ]);

        $sale->amount_paid -= $original->amount;
        if ($sale->status === SaleStatus::Confirmed) {
            $sale->balance_due += $original->amount;
            $sale->payment_status = PaymentStatus::derive($sale->total, $sale->amount_paid);
            $customer->outstanding_balance += $original->amount;
        }
        $sale->save();

        $payment->unallocated_amount += $original->amount;
        $payment->save();

        $customer->credit_balance += $original->amount;
        $customer->save();

        return $row;
    }

    /**
     * Originals not yet reversed, oldest first. A locking (shared) read, so it can sit
     * before the transaction's first plain read. Only stable while the customer row is
     * held: every writer of allocations holds it.
     *
     * @return Collection<int, PaymentAllocation>
     */
    public function effectiveAllocations(string $column, int $id): Collection
    {
        return PaymentAllocation::query()
            ->where($column, $id)
            ->whereNull('reversal_of_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('payment_allocations as r')
                    ->whereColumn('r.reversal_of_id', 'payment_allocations.id');
            })
            ->orderBy('id')
            ->sharedLock()
            ->get();
    }

    private function assertSameCustomer(Customer $customer, Payment $payment, Sale $sale): void
    {
        if ($payment->customer_id !== $customer->id || $sale->customer_id !== $customer->id) {
            throw new LogicException('Payment, sale and customer must belong together.');
        }
    }
}
