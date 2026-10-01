<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdjustStock
{
    public function execute(
        User $user,
        int $productId,
        int $quantity,
        StockMovementType $type,
        string $note,
    ): StockMovement {
        if (! in_array($type, [StockMovementType::Adjustment, StockMovementType::Damage], true)) {
            throw new InvalidArgumentException('Stock adjustment type must be adjustment or damage.');
        }

        return DB::transaction(function () use ($user, $productId, $quantity, $type, $note) {
            $product = Product::query()
                ->whereKey($productId)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceAfter = $product->stock_on_hand + $quantity;

            if ($balanceAfter < 0 && ! Setting::getValue('allow_negative_stock', false)) {
                throw new InsufficientStockException([[
                    'product_id' => $product->id,
                    'sku' => (string) $product->sku,
                    'title' => (string) $product->title,
                    'requested' => -$quantity,
                    'available' => (int) $product->stock_on_hand,
                ]]);
            }

            $occurredAt = Carbon::now();

            $movement = StockMovement::query()->create([
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $balanceAfter,
                'unit_cost' => null,
                'reference_type' => null,
                'reference_id' => null,
                'note' => $note,
                'user_id' => $user->id,
                'occurred_at' => $occurredAt,
            ]);

            $product->stock_on_hand = $balanceAfter;
            $product->save();

            return $movement->load('product');
        });
    }
}
