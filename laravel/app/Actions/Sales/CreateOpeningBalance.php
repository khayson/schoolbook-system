<?php

namespace App\Actions\Sales;

use App\Enums\PaymentStatus;
use App\Enums\SaleSource;
use App\Enums\SaleStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\OpeningBalanceExistsException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Money;
use App\Services\NumberSequenceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * A customer's debt from before the system (paper ledger), as a confirmed invoice with
 * no items and no stock movement (docs/acceptance-phase3.md section 6). Numbered from the
 * invoice sequence with the prefix OB; dated the debt's date (00:00 Accra), so statements
 * show it as "Balance brought forward" on that date. It counts in outstanding balances,
 * payments, aging and statements, never in revenue. One live opening balance per customer
 * (database unique index, see the migration); voiding it releases the slot.
 */
class CreateOpeningBalance
{
    public function __construct(
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{amount: int, date: string, due_date: string, notes?: string|null}  $data  amount in pesewas
     */
    public function execute(User $user, Customer $customer, array $data): Sale
    {
        $amount = (int) $data['amount'];
        if ($amount <= 0 || $amount > Money::MAX_PESEWAS) {
            throw new InvalidInputException('amount', 'The opening balance must be more than zero.');
        }
        $date = Carbon::parse($data['date'])->startOfDay();
        if ($date->isAfter(now()->endOfDay())) {
            throw new InvalidInputException('date', 'The opening balance date cannot be in the future.');
        }
        $due = Carbon::parse($data['due_date'])->startOfDay();

        try {
            return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $customer, $amount, $date, $due, $data) {
                $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $existing = $this->liveOpeningBalance($locked->id);
                if ($existing !== null) {
                    throw new OpeningBalanceExistsException($locked->id, $existing);
                }

                // Number last (spec 5.15); the year of the debt's date.
                $year = (int) $date->format('Y');
                $number = $this->numberSequence->format('OB', $year, $this->numberSequence->next('inv', $year));

                $sale = new Sale([
                    'invoice_no' => $number,
                    'customer_id' => $locked->id,
                    'status' => SaleStatus::Confirmed,
                    'payment_status' => PaymentStatus::derive($amount, 0),
                    'source' => SaleSource::Staff,
                    'sale_date' => $date->toDateString(),
                    'due_date' => $due->toDateString(),
                    'subtotal' => $amount,
                    'discount_total' => 0,
                    'tax_total' => 0,
                    'total' => $amount,
                    'notes' => $data['notes'] ?? 'Opening balance brought forward',
                    'created_by' => $user->id,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => $date,
                ]);
                $sale->is_opening_balance = true;
                $sale->amount_paid = 0;
                $sale->balance_due = $amount;
                $sale->save();

                // Cached receivable, on the locked customer row.
                $locked->outstanding_balance += $amount;
                $locked->save();

                return $sale;
            }))->fresh(['customer']);
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous requests: the database index refused the second.
            throw new OpeningBalanceExistsException($customer->id, $this->liveOpeningBalance($customer->id));
        }
    }

    private function liveOpeningBalance(int $customerId): ?Sale
    {
        return Sale::query()
            ->where('customer_id', $customerId)
            ->where('is_opening_balance', true)
            ->where('status', '<>', SaleStatus::Void->value)
            ->first();
    }
}
