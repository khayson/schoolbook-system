<?php

namespace App\Actions\Inventory;

use App\Actions\Sales\VoidSale;
use App\Enums\StockExchangeStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\StockExchangeStateException;
use App\Models\GoodsReceiptItem;
use App\Models\StockExchange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Confirmed -> void. Refuses if the linked sale is paid or delivered.
 * Voids the sale (restores out stock) and reverses the goods receipt via adjustments.
 */
class VoidStockExchange
{
    public function __construct(
        private readonly VoidSale $voidSale,
        private readonly AdjustStock $adjustStock,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, StockExchange $stockExchange, string $reason): StockExchange
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidInputException('reason', 'A reason is required to void a stock exchange.');
        }

        $voided = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $stockExchange, $reason) {
            $exchange = StockExchange::query()
                ->with(['sale', 'goodsReceipt.items'])
                ->whereKey($stockExchange->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($exchange->status !== StockExchangeStatus::Confirmed) {
                throw new StockExchangeStateException(
                    'Only confirmed stock exchanges can be voided.',
                    'stock_exchange_not_voidable',
                    409,
                    ['stock_exchange_id' => $exchange->id, 'status' => $exchange->status->value],
                );
            }

            if ($exchange->sale !== null) {
                if ((int) $exchange->sale->amount_paid > 0) {
                    throw new StockExchangeStateException(
                        'Cannot void an exchange whose sale has payments applied. Reverse payments first.',
                        'stock_exchange_sale_paid',
                        409,
                        [
                            'stock_exchange_id' => $exchange->id,
                            'sale_id' => $exchange->sale->id,
                            'amount_paid' => (int) $exchange->sale->amount_paid,
                        ],
                    );
                }

                if ($exchange->sale->delivered_at !== null) {
                    throw new StockExchangeStateException(
                        'Cannot void an exchange whose sale has been marked delivered.',
                        'stock_exchange_sale_delivered',
                        409,
                        ['stock_exchange_id' => $exchange->id, 'sale_id' => $exchange->sale->id],
                    );
                }

                $this->voidSale->execute($user, $exchange->sale, $reason);
            }

            if ($exchange->goodsReceipt !== null) {
                $note = 'Void '.$exchange->exchange_no.': '.$reason;
                /** @var GoodsReceiptItem $item */
                foreach ($exchange->goodsReceipt->items as $item) {
                    $this->adjustStock->execute(
                        $user,
                        $item->product_id,
                        -$item->quantity,
                        StockMovementType::Adjustment,
                        $note,
                    );
                }
            }

            $exchange->fill([
                'status' => StockExchangeStatus::Void,
                'voided_at' => Carbon::now(),
                'void_reason' => $reason,
            ]);
            $exchange->save();

            return $exchange;
        }));

        return $voided->fresh([
            'items.product',
            'customer',
            'supplier',
            'createdBy',
            'sale',
            'goodsReceipt',
        ]);
    }
}
