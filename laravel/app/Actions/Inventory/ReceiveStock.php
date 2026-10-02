<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReceiveStock
{
    public function __construct(
        private readonly NumberSequenceService $numberSequence,
    ) {}

    /**
     * @param  array{
     *     supplier_id?: int|null,
     *     supplier_reference?: string|null,
     *     received_at: string|\DateTimeInterface,
     *     notes?: string|null,
     *     items: list<array{product_id: int, quantity: int, unit_cost: int}>
     * }  $data
     */
    public function execute(User $user, array $data): GoodsReceipt
    {
        $items = $this->normalizeItems($data['items'] ?? []);

        return DB::transaction(function () use ($user, $data, $items) {
            $receivedAt = Carbon::parse($data['received_at']);
            $year = (int) $receivedAt->format('Y');

            // Lock each distinct product once, in stable id order (deadlock avoidance).
            $lockedProducts = [];
            foreach ($this->uniqueSortedProductIds($items) as $productId) {
                $lockedProducts[$productId] = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Sequence last (spec 5.14): only after every product lock is held.
            $sequenceNumber = $this->numberSequence->next('grn', $year);
            $receiptNo = $this->numberSequence->format('GRN', $year, $sequenceNumber);

            $receipt = GoodsReceipt::query()->create([
                'receipt_no' => $receiptNo,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'received_at' => $receivedAt,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($items as $item) {
                $product = $lockedProducts[$item['product_id']];
                $quantity = $item['quantity'];
                $unitCost = $item['unit_cost'];
                $balanceAfter = $product->stock_on_hand + $quantity;

                GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ]);

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'type' => StockMovementType::ReceiptIn,
                    'quantity' => $quantity,
                    'balance_after' => $balanceAfter,
                    'unit_cost' => $unitCost,
                    'reference_type' => $receipt->getMorphClass(),
                    'reference_id' => $receipt->id,
                    'note' => null,
                    'user_id' => $user->id,
                    'occurred_at' => $receivedAt,
                ]);

                $product->stock_on_hand = $balanceAfter;
                $product->cost_price = $unitCost;
            }

            foreach ($lockedProducts as $product) {
                $product->save();
            }

            return $receipt->load(['items.product', 'supplier', 'createdBy']);
        });
    }

    /**
     * Merge only lines that share the same product_id and unit_cost.
     * Different costs stay as separate goods_receipt_items (cost history).
     *
     * @param  list<array{product_id: int, quantity: int, unit_cost: int}>  $items
     * @return list<array{product_id: int, quantity: int, unit_cost: int}>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one receipt item is required.');
        }

        $merged = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitCost = (int) ($item['unit_cost'] ?? -1);

            if ($productId <= 0) {
                throw new InvalidArgumentException('Each receipt item needs a valid product_id.');
            }

            if ($quantity <= 0) {
                throw new InvalidArgumentException('Receipt quantity must be greater than zero.');
            }

            if ($unitCost < 0) {
                throw new InvalidArgumentException('Unit cost cannot be negative.');
            }

            $key = $productId.'|'.$unitCost;

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                ];

                continue;
            }

            $merged[$key]['quantity'] += $quantity;
        }

        $lines = array_values($merged);

        usort($lines, function (array $a, array $b): int {
            return [$a['product_id'], $a['unit_cost']] <=> [$b['product_id'], $b['unit_cost']];
        });

        return $lines;
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_cost: int}>  $items
     * @return list<int>
     */
    private function uniqueSortedProductIds(array $items): array
    {
        $ids = array_values(array_unique(array_map(
            fn (array $item): int => $item['product_id'],
            $items,
        )));

        sort($ids);

        return $ids;
    }
}
