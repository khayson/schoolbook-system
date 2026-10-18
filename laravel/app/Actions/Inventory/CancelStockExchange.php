<?php

namespace App\Actions\Inventory;

use App\Enums\StockExchangeStatus;
use App\Exceptions\StockExchangeStateException;
use App\Models\StockExchange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Draft discard. No stock or money effect.
 */
class CancelStockExchange
{
    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, StockExchange $stockExchange): void
    {
        $this->causer->withCauser($user, function () use ($stockExchange) {
            DB::transaction(function () use ($stockExchange) {
                $exchange = StockExchange::query()->whereKey($stockExchange->id)->lockForUpdate()->firstOrFail();

                if ($exchange->status !== StockExchangeStatus::Draft) {
                    throw new StockExchangeStateException(
                        'Only draft stock exchanges can be cancelled.',
                        'stock_exchange_not_cancellable',
                        409,
                        ['stock_exchange_id' => $exchange->id, 'status' => $exchange->status->value],
                    );
                }

                $exchange->delete();
            });
        });
    }
}
