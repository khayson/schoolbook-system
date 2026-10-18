<?php

namespace App\Actions\Inventory;

use App\Actions\Inventory\Concerns\NormalizesStockExchangeItems;
use App\Enums\StockExchangeSettlement;
use App\Enums\StockExchangeStatus;
use App\Exceptions\InvalidInputException;
use App\Models\Customer;
use App\Models\StockExchange;
use App\Models\StockExchangeItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class CreateStockExchange
{
    use NormalizesStockExchangeItems;

    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     supplier_id?: int|null,
     *     counterparty_name: string,
     *     settlement?: string|null,
     *     notes?: string|null,
     *     items: list<array{direction: string, product_id: int, quantity: int, unit_cost?: int|null, unit_price?: int|null}>
     * }  $data
     */
    public function execute(User $user, array $data): StockExchange
    {
        $items = $this->normalizeExchangeItems($data['items'] ?? []);
        $counterparty = trim((string) ($data['counterparty_name'] ?? ''));
        if ($counterparty === '') {
            throw new InvalidInputException(
                'counterparty_name',
                'Enter the other person’s name (the exchanger you are dealing with).',
            );
        }

        $settlement = $this->parseSettlement($data['settlement'] ?? null, required: false);

        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $data, $items, $counterparty, $settlement) {
            $customer = Customer::query()
                ->whereKey((int) ($data['customer_id'] ?? 0))
                ->where('is_active', true)
                ->first();
            if ($customer === null) {
                throw new InvalidInputException(
                    'customer_id',
                    'Pick the dealer account for books they took from you (needed for the invoice).',
                );
            }

            $supplierId = null;
            if (! empty($data['supplier_id'])) {
                $supplier = Supplier::query()->find((int) $data['supplier_id']);
                if ($supplier === null) {
                    throw new InvalidInputException('supplier_id', 'The selected supplier does not exist.');
                }
                $supplierId = $supplier->id;
            }

            $exchange = StockExchange::query()->create([
                'exchange_no' => null,
                'customer_id' => $customer->id,
                'supplier_id' => $supplierId,
                'counterparty_name' => $counterparty,
                'status' => StockExchangeStatus::Draft,
                'settlement' => $settlement,
                'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : null,
                'created_by' => $user->id,
            ]);

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

            return $exchange->load(['items.product', 'customer', 'supplier', 'createdBy']);
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
