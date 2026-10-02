<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\LocksSaleRows;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleDeliveredException;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AllocationLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Confirmed -> void (spec 9.1). Reverses every sale_out with a sale_void_in at the
 * snapshot unit cost. The invoice number stays on the sale (issued numbers are never
 * reused or removed).
 *
 * Money already applied is reversed through the ledger (one reversal row per effective
 * allocation): it returns to each payment's unallocated_amount and the customer's
 * credit_balance, ready for AllocateCredit. Refused once delivered (stock has physically
 * left; returns arrive in Phase 5).
 *
 * Locks: customer -> sale -> payments with money on it (ascending id) -> items -> products.
 */
class VoidSale
{
    use LocksSaleRows;

    public function __construct(
        private readonly AllocationLedger $ledger,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, Sale $sale, string $reason): Sale
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidInputException('reason', 'A reason is required to void a sale.');
        }

        $voided = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $sale, $reason) {
            // Global lock order (spec 5.14): customer -> sale -> payments -> items -> products.
            [$customer, $locked] = $this->lockCustomerAndSale($sale);
            $locked->assertCanTransitionTo(SaleStatus::Void, 'void');

            if ($locked->delivered_at !== null) {
                throw new SaleDeliveredException($locked);
            }

            $effective = $this->ledger->effectiveAllocations('sale_id', $locked->id);
            $payments = Payment::query()
                ->whereKey($effective->pluck('payment_id')->unique()->sort()->values()->all())
                ->where('customer_id', $customer->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $items = $this->lockItems($locked);
            $products = $this->lockProducts($items->pluck('product_id'));

            // Money back to credit first, while the sale is still confirmed (so the
            // ledger also restores balance_due/outstanding, which the void then clears).
            foreach ($effective as $allocation) {
                $this->ledger->reverse($customer, $payments[$allocation->payment_id], $locked, $allocation, $user);
            }

            $voidedAt = Carbon::now();

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $product->stock_on_hand += $item->quantity;

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'type' => StockMovementType::SaleVoidIn,
                    'quantity' => $item->quantity,
                    'balance_after' => $product->stock_on_hand,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => $locked->getMorphClass(),
                    'reference_id' => $locked->id,
                    'note' => $reason,
                    'user_id' => $user->id,
                    'occurred_at' => $voidedAt,
                ]);
            }

            foreach ($products as $product) {
                $product->save();
            }

            $locked->fill([
                'status' => SaleStatus::Void,
                'voided_at' => $voidedAt,
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);
            $priorBalanceDue = $locked->balance_due;
            $locked->balance_due = 0;
            $locked->save();

            // Cached receivable, on the customer row locked first.
            $customer->outstanding_balance -= $priorBalanceDue;
            $customer->save();

            return $locked;
        }));

        return $voided->fresh(['customer', 'items.product', 'createdBy']);
    }
}
