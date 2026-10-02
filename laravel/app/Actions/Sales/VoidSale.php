<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\LocksSaleRows;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleDeliveredException;
use App\Exceptions\SaleHasPaymentsException;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Confirmed -> void (spec 9.1). Reverses every sale_out with a sale_void_in at the
 * snapshot unit cost. The invoice number stays on the sale (issued numbers are never
 * reused or removed).
 *
 * Refused once delivered (stock has physically left; returns arrive in Phase 5) and,
 * until Phase 2C adds allocation reversal, once any payment is applied.
 */
class VoidSale
{
    use LocksSaleRows;

    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, Sale $sale, string $reason): Sale
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidInputException('reason', 'A reason is required to void a sale.');
        }

        $voided = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $sale, $reason) {
            // Global lock order (spec 5.14): customer -> sale -> items -> products (sorted).
            [$customer, $locked] = $this->lockCustomerAndSale($sale);
            $locked->assertCanTransitionTo(SaleStatus::Void, 'void');

            if ($locked->delivered_at !== null) {
                throw new SaleDeliveredException($locked);
            }

            if ($locked->amount_paid > 0) {
                throw new SaleHasPaymentsException($locked);
            }

            $items = $this->lockItems($locked);
            $products = $this->lockProducts($items->pluck('product_id'));

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
