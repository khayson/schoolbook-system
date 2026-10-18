<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\NormalizesStockExchangeItems;
use App\Enums\StockExchangeSettlement;
use App\Enums\StockExchangeStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\StockExchangeStateException;
use App\Models\Customer;
use App\Models\StockExchange;
use App\Models\StockExchangeItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateDraftStockExchange
{
    use NormalizesStockExchangeItems;

    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     customer_id?: int,
     *     supplier_id?: int|null,
     *     counterparty_name?: string,
     *     settlement?: string|null,
     *     notes?: string|null,
     *     items?: list<array{direction: string, product_id: int, quantity: int, unit_cost?: int|null, unit_price?: int|null}>
     * }  $data
     */
    public function execute(User $user, StockExchange $stockExchange, array $data): StockExchange
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($stockExchange, $data) {
            $exchange = StockExchange::query()->whereKey($stockExchange->id)->lockForUpdate()->firstOrFail();

            if ($exchange->status !== StockExchangeStatus::Draft) {
                throw new StockExchangeStateException(
                    'Only draft stock exchanges can be edited.',
                    'stock_exchange_not_draft',
                    409,
                    ['stock_exchange_id' => $exchange->id, 'status' => $exchange->status->value],
                );
            }

            if (array_key_exists('customer_id', $data)) {
                $customer = Customer::query()
                    ->whereKey((int) $data['customer_id'])
                    ->where('is_active', true)
                    ->first();
                if ($customer === null) {
                    throw new InvalidInputException(
                        'customer_id',
                        'Pick the dealer account for books they took from you (needed for the invoice).',
                    );
                }
                $exchange->customer_id = $customer->id;
            }

            if (array_key_exists('supplier_id', $data)) {
                if ($data['supplier_id'] === null || $data['supplier_id'] === '') {
                    $exchange->supplier_id = null;
                } else {
                    $supplier = Supplier::query()->find((int) $data['supplier_id']);
                    if ($supplier === null) {
                        throw new InvalidInputException('supplier_id', 'The selected supplier does not exist.');
                    }
                    $exchange->supplier_id = $supplier->id;
                }
            }

            if (array_key_exists('counterparty_name', $data)) {
                $name = trim((string) $data['counterparty_name']);
                if ($name === '') {
                    throw new InvalidInputException(
                        'counterparty_name',
                        'Enter the other person’s name (the exchanger you are dealing with).',
                    );
                }
                $exchange->counterparty_name = $name;
            }

            if (array_key_exists('settlement', $data)) {
                $exchange->settlement = $this->parseSettlement($data['settlement'], required: false);
            }

            if (array_key_exists('notes', $data)) {
                $exchange->notes = $data['notes'] !== null ? (trim((string) $data['notes']) ?: null) : null;
            }

            if (isset($data['items'])) {
                $items = $this->normalizeExchangeItems($data['items']);
                StockExchangeItem::query()->where('stock_exchange_id', $exchange->id)->delete();
                foreach ($items as $item) {
                    StockExchangeItem::query()->create([
                        'stock_exchange_id' => $exchange->id,
                        'direction' => $item['direction'],
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }
            }

            $exchange->save();

            return $exchange->fresh(['items.product', 'customer', 'supplier', 'createdBy']);
        }));
    }

    private function parseSettlement(mixed $value, bool $required): ?StockExchangeSettlement
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidInputException(
                    'settlement',
                    'Choose how they will pay: cash and carry, installment, or pay later.',
                );
            }

            return null;
        }

        $settlement = StockExchangeSettlement::tryFrom((string) $value);
        if ($settlement === null) {
            throw new InvalidInputException(
                'settlement',
                'Settlement must be cash, installment, or pay_later.',
            );
        }

        return $settlement;
    }
}
