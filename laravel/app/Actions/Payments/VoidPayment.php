<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\LocksLedgerRows;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\PaymentAlreadyVoidException;
use App\Models\Payment;
use App\Models\User;
use App\Services\AllocationLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Voids a payment (spec 9.3): a reversal row for every effective allocation, so each
 * sale owes that money again; then the payment's whole amount (now unallocated) leaves
 * the customer's credit. Nothing is edited or deleted in the ledger.
 *
 * Locks: customer -> affected sales (ascending id) -> the payment.
 */
class VoidPayment
{
    use LocksLedgerRows;

    public function __construct(
        private readonly AllocationLedger $ledger,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, Payment $payment, string $reason): Payment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidInputException('reason', 'A reason is required to void a payment.');
        }

        $voided = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $payment, $reason) {
            // customer_id never changes on a payment, so the route-bound value is safe to lock by.
            $customer = $this->lockPayingCustomer($payment->customer_id);

            $effective = $this->ledger->effectiveAllocations('payment_id', $payment->id);
            $sales = $this->lockCustomerSalesById($customer, $effective->pluck('sale_id')->all());

            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isValid()) {
                throw new PaymentAlreadyVoidException($locked, 'void');
            }

            foreach ($effective as $allocation) {
                $this->ledger->reverse($customer, $locked, $sales[$allocation->sale_id], $allocation, $user);
            }

            // Every peso of the payment is back in its pool; remove the pool from credit.
            $customer->credit_balance -= $locked->unallocated_amount;
            $customer->save();

            $locked->fill([
                'status' => PaymentRecordStatus::Void,
                'void_reason' => $reason,
                'voided_at' => Carbon::now(),
                'voided_by' => $user->id,
            ]);
            $locked->unallocated_amount = 0;
            $locked->save();

            return $locked;
        }));

        return $voided->fresh(['customer', 'allocations.sale', 'receivedBy', 'voidedBy']);
    }
}
