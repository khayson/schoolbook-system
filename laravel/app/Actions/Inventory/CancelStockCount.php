<?php

namespace App\Actions\Inventory;

use App\Exceptions\StockCountNotOpenException;
use App\Models\StockCount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Cancels an open count; nothing is applied. */
class CancelStockCount
{
    public function execute(User $user, StockCount $count): StockCount
    {
        return DB::transaction(function () use ($user, $count) {
            $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new StockCountNotOpenException($locked, 'cancel');
            }

            $locked->update(['status' => StockCount::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id]);

            return $locked->load('items.product');
        });
    }
}
