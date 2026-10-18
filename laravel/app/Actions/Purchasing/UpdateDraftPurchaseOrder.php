<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\PurchaseOrderStateException;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateDraftPurchaseOrder
{
    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     supplier_id?: int,
     *     expected_at?: string|null,
     *     notes?: string|null,
     *     items?: list<array{product_id: int, quantity_ordered: int, unit_cost: int}>
     * }  $data
     */
    public function execute(User $user, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($purchaseOrder, $data) {
            $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== PurchaseOrderStatus::Draft) {
                throw new PurchaseOrderStateException(
                    'Only draft purchase orders can be edited.',
                    'purchase_order_not_draft',
                    409,
                    ['purchase_order_id' => $po->id, 'status' => $po->status->value],
                );
            }

            if (array_key_exists('supplier_id', $data)) {
                $supplier = Supplier::query()->find($data['supplier_id']);
                if ($supplier === null) {
                    throw new InvalidInputException('supplier_id', 'The selected supplier does not exist.');
                }
                $po->supplier_id = $supplier->id;
            }

            if (array_key_exists('expected_at', $data)) {
                $po->expected_at = $data['expected_at'] !== null
                    ? Carbon::parse($data['expected_at'])->toDateString()
                    : null;
            }

            if (array_key_exists('notes', $data)) {
                $po->notes = $data['notes'];
            }

            if (isset($data['items'])) {
                $items = $this->normalizeItems($data['items']);
                PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->delete();
                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += $item['quantity_ordered'] * $item['unit_cost'];
                    PurchaseOrderItem::query()->create([
                        'purchase_order_id' => $po->id,
                        'product_id' => $item['product_id'],
                        'quantity_ordered' => $item['quantity_ordered'],
                        'quantity_received' => 0,
                        'unit_cost' => $item['unit_cost'],
                    ]);
                }
                $po->subtotal = $subtotal;
            }

            $po->save();

            return $po->fresh(['items.product', 'supplier', 'createdBy']);
        }));
    }

    /**
     * @param  list<array{product_id: int, quantity_ordered: int, unit_cost: int}>  $items
     * @return list<array{product_id: int, quantity_ordered: int, unit_cost: int}>
     */
    private function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidInputException('items', 'A purchase order needs at least one line item.');
        }

        $merged = [];
        foreach ($items as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity_ordered'] ?? 0);
            $cost = (int) ($item['unit_cost'] ?? -1);

            if ($productId <= 0 || ! Product::query()->whereKey($productId)->exists()) {
                throw new InvalidInputException("items.{$index}.product_id", 'Each line needs a valid product_id.');
            }
            if ($qty <= 0) {
                throw new InvalidInputException("items.{$index}.quantity_ordered", 'Quantity ordered must be greater than zero.');
            }
            if ($cost < 0) {
                throw new InvalidInputException("items.{$index}.unit_cost", 'Unit cost cannot be negative.');
            }

            if (! isset($merged[$productId])) {
                $merged[$productId] = [
                    'product_id' => $productId,
                    'quantity_ordered' => $qty,
                    'unit_cost' => $cost,
                ];
            } else {
                if ($merged[$productId]['unit_cost'] !== $cost) {
                    throw new InvalidInputException(
                        "items.{$index}.unit_cost",
                        'The same product cannot appear with different unit costs on one PO.',
                    );
                }
                $merged[$productId]['quantity_ordered'] += $qty;
            }
        }

        return array_values($merged);
    }
}
