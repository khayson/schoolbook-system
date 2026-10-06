<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\CountConflictException;
use App\Exceptions\StockCountNotOpenException;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies an open count once (owner). Each counted item with a non-zero variance becomes
 * ONE count_adjustment movement of exactly that variance (reference stock_count), so
 * sales and receipts between counting and applying are preserved; uncounted items are
 * untouched. If any adjustment would leave stock below zero while allow_negative_stock
 * is off, nothing is written (count_conflict). Locks: the count row first, then products
 * in ascending id order. A second apply is 409 stock_count_not_open.
 */
class ApplyStockCount
{
    public function execute(User $user, StockCount $count): StockCount
    {
        return DB::transaction(function () use ($user, $count) {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new StockCountNotOpenException($locked, 'apply');
            }

            $items = $locked->items()
                ->whereNotNull('counted_qty')
                ->where('variance', '<>', 0)
                ->orderBy('product_id')
                ->get();

            $products = [];
            foreach ($items as $item) {
                $products[$item->product_id] = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
            }

            if (! Setting::getValue('allow_negative_stock', false)) {
                $conflicts = [];
                foreach ($items as $item) {
                    $product = $products[$item->product_id];
                    $resulting = $product->stock_on_hand + $item->variance;
                    if ($resulting < 0) {
                        $conflicts[] = [
                            'product_id' => $product->id,
                            'sku' => (string) $product->sku,
                            'title' => (string) $product->title,
                            'stock_on_hand' => (int) $product->stock_on_hand,
                            'variance' => $item->variance,
                            'resulting' => $resulting,
                        ];
                    }
                }
                if ($conflicts !== []) {
                    throw new CountConflictException($conflicts);
                }
            }

            $now = Carbon::now();
            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $balanceAfter = $product->stock_on_hand + $item->variance;

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'type' => StockMovementType::CountAdjustment,
                    'quantity' => $item->variance,
                    'balance_after' => $balanceAfter,
                    'unit_cost' => $product->cost_price,
                    'reference_type' => $locked->getMorphClass(),
                    'reference_id' => $locked->id,
                    'note' => "Stock count {$locked->reference}",
                    'user_id' => $user->id,
                    'occurred_at' => $now,
                ]);

                $item->update(['unit_cost' => $product->cost_price]);
                $product->stock_on_hand = $balanceAfter;
                $product->save();
            }

            $locked->update(['status' => StockCount::APPLIED, 'applied_at' => $now, 'applied_by' => $user->id]);

            return $locked->load('items.product');
        });
    }
}
