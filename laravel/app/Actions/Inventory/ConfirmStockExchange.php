<?php

namespace App\Actions\Inventory;

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Enums\StockExchangeDirection;
use App\Enums\StockExchangeSettlement;
use App\Enums\StockExchangeStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\StockExchangeStateException;
use App\Models\Product;
use App\Models\StockExchange;
use App\Models\User;
use App\Services\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Draft -> confirmed.
 *
 * Process: who (dealer + other person) → books each side took (qty + price) →
 * settlement (cash / installment / pay later) → confirm.
 *
 * Out lines → sale invoice. In lines → goods receipt. Settlement is stored on the
 * exchange; cash/installment clients open the sale to record money.
 *
 * @param  array{
 *     settlement?: string|null,
 *     override_credit_limit?: bool,
 *     apply_credit?: bool,
 *     due_date?: string|\DateTimeInterface|null
 * }  $options
 */
class ConfirmStockExchange
{
    public function __construct(
        private readonly CreateDraftSale $createDraftSale,
        private readonly ConfirmSale $confirmSale,
        private readonly ReceiveStock $receiveStock,
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     settlement?: string|null,
     *     override_credit_limit?: bool,
     *     apply_credit?: bool,
     *     due_date?: string|\DateTimeInterface|null
     * }  $options
     */
    public function execute(User $user, StockExchange $stockExchange, array $options = []): StockExchange
    {
        $confirmed = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $stockExchange, $options) {
            $exchange = StockExchange::query()
                ->with('items')
                ->whereKey($stockExchange->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($exchange->status !== StockExchangeStatus::Draft) {
                throw new StockExchangeStateException(
                    'Only draft stock exchanges can be confirmed.',
                    'stock_exchange_not_draft',
                    409,
                    ['stock_exchange_id' => $exchange->id, 'status' => $exchange->status->value],
                );
            }

            if ($exchange->items->isEmpty()) {
                throw new InvalidInputException(
                    'items',
                    'Add at least one book (who took it, which title, and how many) before confirming.',
                );
            }

            $settlement = $this->resolveSettlement($exchange, $options);
            $exchange->settlement = $settlement;

            $outItems = $exchange->items->where('direction', StockExchangeDirection::Out)->values();
            $inItems = $exchange->items->where('direction', StockExchangeDirection::In)->values();

            if ($outItems->isEmpty() && in_array($settlement, [StockExchangeSettlement::Cash, StockExchangeSettlement::Installment, StockExchangeSettlement::PayLater], true)) {
                // Settlement only applies when there is an invoice (they took books).
                // Taken-only exchanges may still store a settlement for notes, but cash/installment has no sale.
            }

            $saleId = null;
            $goodsReceiptId = null;
            $confirmedAt = Carbon::now();
            $dueDate = $this->dueDateForSettlement($settlement, $options['due_date'] ?? null, $confirmedAt);

            if ($outItems->isNotEmpty()) {
                $draft = $this->createDraftSale->execute($user, [
                    'customer_id' => $exchange->customer_id,
                    'sale_date' => $confirmedAt->toDateString(),
                    'due_date' => $dueDate,
                    'notes' => $this->saleNotes($exchange, $settlement),
                    'items' => $outItems->map(function ($item): array {
                        $line = [
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                        ];
                        if ($item->unit_price !== null) {
                            $line['override_unit_price'] = $item->unit_price;
                            $line['override_reason'] = 'Stock exchange price';
                        }

                        return $line;
                    })->all(),
                ]);

                $sale = $this->confirmSale->execute($user, $draft, [
                    'override_credit_limit' => (bool) ($options['override_credit_limit'] ?? false),
                    'apply_credit' => (bool) ($options['apply_credit'] ?? false),
                    'due_date' => $dueDate,
                ]);
                $saleId = $sale->id;
            }

            if ($inItems->isNotEmpty()) {
                $productCosts = Product::query()
                    ->whereKey($inItems->pluck('product_id')->all())
                    ->pluck('cost_price', 'id');

                $receipt = $this->receiveStock->execute($user, [
                    'supplier_id' => $exchange->supplier_id,
                    'supplier_reference' => 'EXC draft #'.$exchange->id,
                    'received_at' => $confirmedAt,
                    'notes' => $this->receiptNotes($exchange),
                    'items' => $inItems->map(fn ($item): array => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_cost' => $item->unit_cost ?? (int) ($productCosts[$item->product_id] ?? 0),
                    ])->all(),
                ]);
                $goodsReceiptId = $receipt->id;
            }

            $year = (int) $confirmedAt->format('Y');
            $exchangeNo = $this->numberSequence->format(
                'EXC',
                $year,
                $this->numberSequence->next('exc', $year),
            );

            $exchange->fill([
                'exchange_no' => $exchangeNo,
                'status' => StockExchangeStatus::Confirmed,
                'sale_id' => $saleId,
                'goods_receipt_id' => $goodsReceiptId,
                'confirmed_at' => $confirmedAt,
            ]);
            $exchange->save();

            if ($goodsReceiptId !== null) {
                $exchange->goodsReceipt()->update([
                    'supplier_reference' => $exchangeNo,
                ]);
            }

            return $exchange;
        }));

        return $confirmed->fresh([
            'items.product',
            'customer',
            'supplier',
            'createdBy',
            'sale.items.product',
            'goodsReceipt.items.product',
        ]);
    }

    /**
     * @param  array{settlement?: string|null}  $options
     */
    private function resolveSettlement(StockExchange $exchange, array $options): StockExchangeSettlement
    {
        $raw = $options['settlement'] ?? $exchange->settlement?->value;
        if ($raw instanceof StockExchangeSettlement) {
            return $raw;
        }

        $settlement = StockExchangeSettlement::tryFrom((string) ($raw ?? ''));
        if ($settlement === null) {
            throw new InvalidInputException(
                'settlement',
                'Choose how they will pay for books they took: cash and carry, installment, or pay later.',
            );
        }

        return $settlement;
    }

    private function dueDateForSettlement(
        StockExchangeSettlement $settlement,
        mixed $requested,
        Carbon $confirmedAt,
    ): ?string {
        if (! empty($requested)) {
            return Carbon::parse($requested)->toDateString();
        }

        return match ($settlement) {
            StockExchangeSettlement::Cash => $confirmedAt->toDateString(),
            StockExchangeSettlement::Installment,
            StockExchangeSettlement::PayLater => null, // ConfirmSale uses payment terms
        };
    }

    private function saleNotes(StockExchange $exchange, StockExchangeSettlement $settlement): string
    {
        $base = 'Exchange with '.$exchange->counterparty_name.' · '.$settlement->label();
        if ($exchange->notes) {
            return $base.' — '.$exchange->notes;
        }

        return $base;
    }

    private function receiptNotes(StockExchange $exchange): string
    {
        $base = 'Exchange with '.$exchange->counterparty_name.' (Taken)';
        if ($exchange->notes) {
            return $base.' — '.$exchange->notes;
        }

        return $base;
    }
}
