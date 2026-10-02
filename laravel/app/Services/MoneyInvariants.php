<?php

namespace App\Services;

use App\DTOs\Ledger\InvariantViolation;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The money invariants (spec 5.17 / 9.5) in one place. The allocation ledger
 * (payment_allocations) is the source of truth; everything else is a cache of it.
 *
 *   sale_amount_paid          sales.amount_paid = sum of the sale's allocation rows
 *   sale_balance_due          confirmed: balance_due = total - amount_paid; otherwise 0
 *   sale_payment_status       confirmed: payment_status = derive(total, amount_paid)
 *   payment_allocations       valid: sum(allocations) + unallocated_amount = amount;
 *                             void: sum(allocations) = 0 and unallocated_amount = 0
 *   customer_credit_balance   credit_balance = sum(unallocated_amount) of valid payments
 *   customer_outstanding      outstanding_balance = sum(balance_due) of confirmed sales
 *   allocation_ledger         originals are positive; a reversal negates exactly one
 *                             original on the same payment and sale
 *
 * check() is read-only, set-based SQL (no per-row PHP loops) and works on SQLite and
 * MySQL. repair() rebuilds the caches from the ledger for one customer under the
 * global lock order. Ledger rows themselves are never repaired, only reported.
 */
class MoneyInvariants
{
    /**
     * @param  list<int>|null  $customerIds  limit to these customers; null = everyone
     * @return list<InvariantViolation>
     */
    public function check(?array $customerIds = null): array
    {
        // One transaction so every query reads the same MySQL REPEATABLE READ snapshot
        // (taken at the first read). Otherwise a payment committing between two queries
        // could show up as a false violation in the nightly run.
        return DB::transaction(fn (): array => [
            ...$this->saleAmountPaid($customerIds),
            ...$this->saleBalanceDue($customerIds),
            ...$this->salePaymentStatus($customerIds),
            ...$this->paymentAllocations($customerIds),
            ...$this->customerCreditBalance($customerIds),
            ...$this->customerOutstanding($customerIds),
            ...$this->allocationLedger($customerIds),
        ]);
    }

    /**
     * Rebuild every cache for one customer from the allocation ledger.
     * Lock order (spec 5.14): customer -> sales -> payments.
     *
     * @return int number of rows changed
     */
    public function repair(int $customerId): int
    {
        return DB::transaction(function () use ($customerId): int {
            $customer = Customer::withTrashed()->whereKey($customerId)->lockForUpdate()->firstOrFail();
            $sales = Sale::query()->where('customer_id', $customerId)->orderBy('id')->lockForUpdate()->get();
            $payments = Payment::query()->where('customer_id', $customerId)->orderBy('id')->lockForUpdate()->get();

            $bySale = $this->ledgerSums('sale_id', $sales->modelKeys());
            $byPayment = $this->ledgerSums('payment_id', $payments->modelKeys());
            $changed = 0;

            foreach ($sales as $sale) {
                $paid = $bySale[$sale->id] ?? 0;
                if ($paid < 0) {
                    continue; // corrupt ledger: report only
                }

                $sale->amount_paid = $paid;
                if ($sale->status === SaleStatus::Confirmed) {
                    $sale->balance_due = max(0, $sale->total - $paid);
                    $sale->payment_status = PaymentStatus::derive($sale->total, $paid);
                } else {
                    $sale->balance_due = 0;
                }

                if ($sale->isDirty()) {
                    $sale->save();
                    $changed++;
                }
            }

            foreach ($payments as $payment) {
                $unallocated = $payment->isValid() ? $payment->amount - ($byPayment[$payment->id] ?? 0) : 0;
                if ($unallocated < 0) {
                    continue; // over-allocated payment: report only
                }

                $payment->unallocated_amount = $unallocated;
                if ($payment->isDirty()) {
                    $payment->save();
                    $changed++;
                }
            }

            $customer->credit_balance = (int) $payments->filter->isValid()->sum('unallocated_amount');
            $customer->outstanding_balance = (int) $sales
                ->filter(fn (Sale $sale): bool => $sale->status === SaleStatus::Confirmed)
                ->sum('balance_due');

            if ($customer->isDirty()) {
                $customer->save();
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function ledgerSums(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('payment_allocations')
            ->whereIn($column, $ids)
            ->groupBy($column)
            ->selectRaw("{$column} as id, sum(amount) as total")
            ->pluck('total', 'id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @return list<InvariantViolation>
     */
    private function saleAmountPaid(?array $customerIds): array
    {
        $ledger = DB::table('payment_allocations')
            ->selectRaw('sale_id, sum(amount) as total')
            ->groupBy('sale_id');

        $rows = $this->forCustomers(DB::table('sales as s'), 's.customer_id', $customerIds)
            ->leftJoinSub($ledger, 'a', 'a.sale_id', '=', 's.id')
            ->whereRaw('s.amount_paid <> coalesce(a.total, 0)')
            ->selectRaw('s.id, s.customer_id, s.amount_paid as actual, coalesce(a.total, 0) as expected')
            ->get();

        return $this->violations('sale_amount_paid', 'sale', 'amount_paid', $rows);
    }

    /**
     * Written as balance_due + amount_paid = total so MySQL never subtracts unsigned columns.
     *
     * @return list<InvariantViolation>
     */
    private function saleBalanceDue(?array $customerIds): array
    {
        $confirmed = SaleStatus::Confirmed->value;

        $rows = $this->forCustomers(DB::table('sales as s'), 's.customer_id', $customerIds)
            ->where(function (Builder $q) use ($confirmed) {
                $q->where(fn (Builder $c) => $c->where('s.status', $confirmed)->whereRaw('s.balance_due + s.amount_paid <> s.total'))
                    ->orWhere(fn (Builder $c) => $c->where('s.status', '<>', $confirmed)->where('s.balance_due', '<>', 0));
            })
            ->select('s.id', 's.customer_id', 's.status', 's.total', 's.amount_paid', 's.balance_due as actual')
            ->get()
            ->map(function (object $row) use ($confirmed): object {
                $row->expected = $row->status === $confirmed ? (int) $row->total - (int) $row->amount_paid : 0;

                return $row;
            });

        return $this->violations('sale_balance_due', 'sale', 'balance_due', $rows);
    }

    /**
     * @return list<InvariantViolation>
     */
    private function salePaymentStatus(?array $customerIds): array
    {
        $derived = sprintf(
            "case when s.amount_paid >= s.total then '%s' when s.amount_paid > 0 then '%s' else '%s' end",
            PaymentStatus::Paid->value,
            PaymentStatus::Partial->value,
            PaymentStatus::Unpaid->value,
        );

        $rows = $this->forCustomers(DB::table('sales as s'), 's.customer_id', $customerIds)
            ->where('s.status', SaleStatus::Confirmed->value)
            ->whereRaw("s.payment_status <> ({$derived})")
            ->selectRaw("s.id, s.customer_id, s.payment_status as actual, ({$derived}) as expected")
            ->get();

        return $this->violations('sale_payment_status', 'sale', 'payment_status', $rows, numeric: false);
    }

    /**
     * @return list<InvariantViolation>
     */
    private function paymentAllocations(?array $customerIds): array
    {
        $valid = PaymentRecordStatus::Valid->value;
        $ledger = DB::table('payment_allocations')
            ->selectRaw('payment_id, sum(amount) as total')
            ->groupBy('payment_id');

        $rows = $this->forCustomers(DB::table('payments as p'), 'p.customer_id', $customerIds)
            ->leftJoinSub($ledger, 'a', 'a.payment_id', '=', 'p.id')
            ->where(function (Builder $q) use ($valid) {
                $q->where(fn (Builder $c) => $c->where('p.status', $valid)->whereRaw('coalesce(a.total, 0) + p.unallocated_amount <> p.amount'))
                    ->orWhere(fn (Builder $c) => $c->where('p.status', '<>', $valid)->whereRaw('(coalesce(a.total, 0) <> 0 or p.unallocated_amount <> 0)'));
            })
            ->selectRaw('p.id, p.customer_id, p.status, p.amount, p.unallocated_amount, coalesce(a.total, 0) as allocated')
            ->get();

        $violations = [];
        foreach ($rows as $row) {
            $isValid = $row->status === $valid;
            $violations[] = new InvariantViolation(
                invariant: 'payment_allocations',
                subjectType: 'payment',
                subjectId: (int) $row->id,
                customerId: (int) $row->customer_id,
                field: $isValid ? 'allocated + unallocated_amount' : 'allocated, unallocated_amount (void)',
                expected: $isValid ? (int) $row->amount : 0,
                actual: $isValid ? (int) $row->allocated + (int) $row->unallocated_amount : "{$row->allocated}, {$row->unallocated_amount}",
            );
        }

        return $violations;
    }

    /**
     * @return list<InvariantViolation>
     */
    private function customerCreditBalance(?array $customerIds): array
    {
        $credit = DB::table('payments')
            ->where('status', PaymentRecordStatus::Valid->value)
            ->selectRaw('customer_id, sum(unallocated_amount) as total')
            ->groupBy('customer_id');

        $rows = $this->forCustomers(DB::table('customers as c'), 'c.id', $customerIds)
            ->leftJoinSub($credit, 'p', 'p.customer_id', '=', 'c.id')
            ->whereRaw('c.credit_balance <> coalesce(p.total, 0)')
            ->selectRaw('c.id, c.id as customer_id, c.credit_balance as actual, coalesce(p.total, 0) as expected')
            ->get();

        return $this->violations('customer_credit_balance', 'customer', 'credit_balance', $rows);
    }

    /**
     * @return list<InvariantViolation>
     */
    private function customerOutstanding(?array $customerIds): array
    {
        $outstanding = DB::table('sales')
            ->where('status', SaleStatus::Confirmed->value)
            ->selectRaw('customer_id, sum(balance_due) as total')
            ->groupBy('customer_id');

        $rows = $this->forCustomers(DB::table('customers as c'), 'c.id', $customerIds)
            ->leftJoinSub($outstanding, 's', 's.customer_id', '=', 'c.id')
            ->whereRaw('c.outstanding_balance <> coalesce(s.total, 0)')
            ->selectRaw('c.id, c.id as customer_id, c.outstanding_balance as actual, coalesce(s.total, 0) as expected')
            ->get();

        return $this->violations('customer_outstanding', 'customer', 'outstanding_balance', $rows);
    }

    /**
     * Originals must be positive. A reversal must be negative, reverse an original (not
     * another reversal), on the same payment and sale, by exactly the negated amount.
     * (Each original is reversed at most once: unique index on reversal_of_id.)
     *
     * @return list<InvariantViolation>
     */
    private function allocationLedger(?array $customerIds): array
    {
        $query = DB::table('payment_allocations as r')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->leftJoin('payment_allocations as o', 'o.id', '=', 'r.reversal_of_id')
            ->where(function (Builder $q) {
                $q->where(fn (Builder $c) => $c->whereNull('r.reversal_of_id')->where('r.amount', '<=', 0))
                    ->orWhere(fn (Builder $c) => $c->whereNotNull('r.reversal_of_id')->where(function (Builder $bad) {
                        $bad->whereNotNull('o.reversal_of_id')
                            ->orWhereColumn('o.payment_id', '<>', 'r.payment_id')
                            ->orWhereColumn('o.sale_id', '<>', 'r.sale_id')
                            ->orWhereRaw('r.amount + o.amount <> 0');
                    }));
            })
            ->selectRaw('r.id, p.customer_id, r.amount as actual, r.reversal_of_id, o.amount as original_amount');

        $rows = $this->forCustomers($query, 'p.customer_id', $customerIds)
            ->get()
            ->map(function (object $row): object {
                $row->expected = $row->reversal_of_id === null ? '> 0' : -1 * (int) $row->original_amount;

                return $row;
            });

        return $this->violations('allocation_ledger', 'payment_allocation', 'amount', $rows, numeric: false);
    }

    private function forCustomers(Builder $query, string $column, ?array $customerIds): Builder
    {
        return $customerIds === null ? $query : $query->whereIn($column, $customerIds);
    }

    /**
     * @param  iterable<object>  $rows  each with id, customer_id, expected, actual
     * @return list<InvariantViolation>
     */
    private function violations(string $invariant, string $subjectType, string $field, iterable $rows, bool $numeric = true): array
    {
        $violations = [];
        foreach ($rows as $row) {
            $violations[] = new InvariantViolation(
                invariant: $invariant,
                subjectType: $subjectType,
                subjectId: (int) $row->id,
                customerId: $row->customer_id === null ? null : (int) $row->customer_id,
                field: $field,
                expected: $numeric ? (int) $row->expected : $row->expected,
                actual: $numeric ? (int) $row->actual : $row->actual,
            );
        }

        return $violations;
    }
}
