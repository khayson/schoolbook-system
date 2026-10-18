<?php

namespace App\Actions\Sales;

use App\Actions\Payments\VoidPayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\RestockCondition;
use App\Enums\SaleReturnStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleReturnStateException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Void a confirmed return: void return_credit first (customer→sales locks), then
 * reverse good restock (return_out) and damaged audit rows without stock change.
 */
class VoidSaleReturn
{
    public function __construct(
        private readonly VoidPayment $voidPayment,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, SaleReturn $saleReturn, string $reason): SaleReturn
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidInputException('reason', 'A reason is required to void a return.');
        }

        $voided = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $saleReturn, $reason) {
            $locked = SaleReturn::query()->whereKey($saleReturn->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SaleReturnStatus::Confirmed) {
                throw new SaleReturnStateException(
                    'Only confirmed returns can be voided.',
                    'sale_return_not_confirmed',
                    409,
                    ['sale_return_id' => $locked->id, 'status' => $locked->status->value],
                );
            }

            $label = $locked->return_no ?? "return #{$locked->id}";

            $payment = Payment::query()
                ->where('customer_id', $locked->customer_id)
                ->where('method', PaymentMethod::ReturnCredit)
                ->where('reference', $locked->return_no)
                ->where('status', PaymentRecordStatus::Valid)
                ->first();

            // Money first so VoidPayment's customer→sales locks precede product locks.
            if ($payment !== null) {
                $this->voidPayment->execute($user, $payment, "Void return {$label}: {$reason}");
            }

            $items = SaleReturnItem::query()
                ->where('sale_return_id', $locked->id)
                ->orderBy('id')
                ->get();

            $productIds = $items->pluck('product_id')->unique()->sort()->values()->all();
            /** @var array<int, Product> $products */
            $products = [];
            foreach ($productIds as $productId) {
                $products[$productId] = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            }

            $now = Carbon::now();

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                if ($item->restock_condition === RestockCondition::Good) {
                    $balanceAfter = $product->stock_on_hand - $item->quantity;
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'type' => StockMovementType::ReturnOut,
                        'quantity' => -$item->quantity,
                        'balance_after' => $balanceAfter,
                        'unit_cost' => $item->unit_cost,
                        'reference_type' => $locked->getMorphClass(),
                        'reference_id' => $locked->id,
                        'note' => "Void {$label} (good reverse)",
                        'user_id' => $user->id,
                        'occurred_at' => $now,
                    ]);
                    $product->stock_on_hand = $balanceAfter;
                } else {
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'type' => StockMovementType::Damage,
                        'quantity' => -$item->quantity,
                        'balance_after' => $product->stock_on_hand,
                        'unit_cost' => $item->unit_cost,
                        'reference_type' => $locked->getMorphClass(),
                        'reference_id' => $locked->id,
                        'note' => "Void {$label} damaged audit reverse (stock unchanged)",
                        'user_id' => $user->id,
                        'occurred_at' => $now,
                    ]);
                }
            }

            foreach ($products as $product) {
                $product->save();
            }

            $locked->fill([
                'status' => SaleReturnStatus::Void,
                'voided_at' => $now,
                'void_reason' => $reason,
            ]);
            $locked->save();

            return $locked;
        }));

        return $voided->fresh(['items.product', 'sale', 'customer', 'createdBy']);
    }
}
