<?php

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\LocksLedgerRows;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Exceptions\AllocationExceedsPaymentException;
use App\Exceptions\InvalidInputException;
use App\Models\Payment;
use App\Models\User;
use App\Services\AllocationLedger;
use App\Services\Money;
use App\Services\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records money received from a customer (spec 9.3).
 *
 * Allocation: explicit allocations[{sale_id, amount}] if given; otherwise, when
 * auto_allocate (default true), oldest-due first across the customer's open invoices;
 * otherwise none. Whatever is not allocated stays on the payment as unallocated_amount,
 * i.e. customer credit.
 *
 * Locks: customer -> the sales being paid (ascending id) -> receipt sequence LAST.
 */
class RecordPayment
{
    use LocksLedgerRows;

    public function __construct(
        private readonly AllocationLedger $ledger,
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     amount: int,
     *     method: string|PaymentMethod,
     *     reference?: string|null,
     *     paid_at?: string|\DateTimeInterface|null,
     *     notes?: string|null,
     *     auto_allocate?: bool,
     *     allocations?: list<array{sale_id: int, amount: int}>|null
     * }  $data
     */
    public function execute(User $user, array $data): Payment
    {
        [$amount, $method, $reference, $paidAt] = $this->validated($data);
        $explicit = $data['allocations'] ?? null;
        $auto = (bool) ($data['auto_allocate'] ?? true);

        $payment = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $data, $amount, $method, $reference, $paidAt, $explicit, $auto) {
            $customer = $this->lockPayingCustomer((int) $data['customer_id']);

            if (! empty($explicit)) {
                $sales = $this->lockCustomerSalesById($customer, array_map(fn (array $a): int => (int) $a['sale_id'], $explicit));
                $plan = $this->planExplicit($customer, $sales, $explicit);

                $allocated = array_sum($plan);
                if ($allocated > $amount) {
                    throw new AllocationExceedsPaymentException($amount, $allocated);
                }
            } elseif ($auto) {
                $sales = $this->lockOpenSalesOldestFirst($customer)->keyBy('id');
                $plan = $this->planOldestFirst($sales->values(), $amount);
            } else {
                $sales = collect();
                $plan = [];
            }

            // Receipt number last; year of the recording date (not paid_at).
            $recordedAt = Carbon::now();
            $year = (int) $recordedAt->format('Y');
            $receiptNo = $this->numberSequence->format('RCT', $year, $this->numberSequence->next('rct', $year));

            $payment = new Payment([
                'receipt_no' => $receiptNo,
                'customer_id' => $customer->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => $paidAt,
                'status' => PaymentRecordStatus::Valid,
                'notes' => $data['notes'] ?? null,
                'received_by' => $user->id,
            ]);
            // The whole amount arrives as credit; allocations then move it onto invoices.
            $payment->unallocated_amount = $amount;
            $payment->save();

            $customer->credit_balance += $amount;
            $customer->save();

            foreach ($plan as $saleId => $allocation) {
                $this->ledger->allocate($customer, $payment, $sales[$saleId], $allocation, $user);
            }

            return $payment;
        }));

        return $payment->fresh(['customer', 'allocations.sale', 'receivedBy']);
    }

    /**
     * Second line of defence behind StorePaymentRequest.
     *
     * @return array{0: int, 1: PaymentMethod, 2: string|null, 3: Carbon}
     */
    private function validated(array $data): array
    {
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidInputException('amount', 'The amount must be greater than zero.');
        }
        if ($amount > Money::MAX_PESEWAS) {
            throw new InvalidInputException('amount', 'The amount is larger than any single payment the system accepts.');
        }

        $method = $data['method'] instanceof PaymentMethod
            ? $data['method']
            : PaymentMethod::tryFrom((string) ($data['method'] ?? ''));
        if ($method === null) {
            throw new InvalidInputException('method', 'Choose cash, momo, bank_transfer or cheque.');
        }

        $reference = isset($data['reference']) ? trim((string) $data['reference']) : '';
        if ($method->requiresReference() && $reference === '') {
            throw new InvalidInputException('reference', 'A reference is required for MoMo, bank transfer and cheque payments.');
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : Carbon::now();
        if ($paidAt->isFuture()) {
            throw new InvalidInputException('paid_at', 'The payment date cannot be in the future.');
        }

        return [$amount, $method, $reference === '' ? null : $reference, $paidAt];
    }
}
