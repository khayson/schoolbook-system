<?php

namespace App\Actions\Sales;

use App\Enums\RestockCondition;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleReturnStateException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class CreateSaleReturn
{
    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     sale_id: int,
     *     reason?: string|null,
     *     items: list<array{sale_item_id: int, quantity: int, restock_condition: string}>
     * }  $data
     */
    public function execute(User $user, array $data): SaleReturn
    {
        $items = $data['items'] ?? [];
        if ($items === []) {
            throw new InvalidInputException('items', 'A return needs at least one line item.');
        }

        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $data, $items) {
            $sale = Sale::query()->with('items')->find($data['sale_id'] ?? 0);
            if ($sale === null) {
                throw new InvalidInputException('sale_id', 'The selected sale does not exist.');
            }
            if ($sale->status !== SaleStatus::Confirmed) {
                throw new SaleReturnStateException(
                    'Only confirmed sales can be returned.',
                    'sale_not_returnable',
                    409,
                    ['sale_id' => $sale->id, 'status' => $sale->status->value],
                );
            }

            $returnable = $this->returnableBySaleItem($sale);
            $subtotal = 0;
            $normalized = [];

            foreach ($items as $index => $raw) {
                $saleItemId = (int) ($raw['sale_item_id'] ?? 0);
                $quantity = (int) ($raw['quantity'] ?? 0);
                $condition = RestockCondition::tryFrom((string) ($raw['restock_condition'] ?? ''));

                if ($saleItemId <= 0) {
                    throw new InvalidInputException("items.{$index}.sale_item_id", 'Each line needs a sale_item_id.');
                }
                if ($quantity <= 0) {
                    throw new InvalidInputException("items.{$index}.quantity", 'Return quantity must be greater than zero.');
                }
                if ($condition === null) {
                    throw new InvalidInputException("items.{$index}.restock_condition", 'Restock condition must be good or damaged.');
                }

                /** @var SaleItem|null $saleItem */
                $saleItem = $sale->items->firstWhere('id', $saleItemId);
                if ($saleItem === null) {
                    throw new InvalidInputException("items.{$index}.sale_item_id", 'That line is not on the selected sale.');
                }

                $available = $returnable[$saleItemId] ?? 0;
                if ($quantity > $available) {
                    throw new InvalidInputException(
                        "items.{$index}.quantity",
                        "Cannot return more than {$available} of sale item {$saleItemId} (sold minus already returned).",
                    );
                }

                $lineTotal = $saleItem->unit_price * $quantity;
                $subtotal += $lineTotal;
                $normalized[] = [
                    'sale_item' => $saleItem,
                    'quantity' => $quantity,
                    'condition' => $condition,
                    'line_total' => $lineTotal,
                ];
                $returnable[$saleItemId] -= $quantity;
            }

            $return = SaleReturn::query()->create([
                'return_no' => null,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'status' => SaleReturnStatus::Draft,
                'reason' => isset($data['reason']) ? trim((string) $data['reason']) ?: null : null,
                'subtotal' => $subtotal,
                'tax_total' => 0,
                'total' => $subtotal,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                SaleReturnItem::query()->create([
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $line['sale_item']->id,
                    'product_id' => $line['sale_item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['sale_item']->unit_price,
                    'line_total' => $line['line_total'],
                    'restock_condition' => $line['condition'],
                    'unit_cost' => $line['sale_item']->unit_cost,
                ]);
            }

            return $return->load(['items.product', 'sale', 'customer', 'createdBy']);
        }));
    }

    /**
     * @return array<int, int> sale_item_id => remaining returnable qty
     */
    public function returnableBySaleItem(Sale $sale): array
    {
        $returned = SaleReturnItem::query()
            ->whereHas('saleReturn', fn ($q) => $q
                ->where('sale_id', $sale->id)
                ->where('status', SaleReturnStatus::Confirmed))
            ->selectRaw('sale_item_id, SUM(quantity) as qty')
            ->groupBy('sale_item_id')
            ->pluck('qty', 'sale_item_id');

        $map = [];
        foreach ($sale->items as $item) {
            $map[$item->id] = max(0, $item->quantity - (int) ($returned[$item->id] ?? 0));
        }

        return $map;
    }
}
