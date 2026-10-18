<?php

namespace App\Actions\Purchasing;

use App\Actions\Inventory\ReceiveStock;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidInputException;
use App\Exceptions\PurchaseOrderStateException;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Receive stock against an open PO: creates a goods receipt (ReceiveStock) and
 * increments quantity_received; status becomes partial or received.
 */
class ReceiveAgainstPurchaseOrder
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{
     *     received_at?: string|\DateTimeInterface,
     *     notes?: string|null,
     *     supplier_reference?: string|null,
     *     items: list<array{purchase_order_item_id: int, quantity: int}>
     * }  $data
     */
    public function execute(User $user, PurchaseOrder $purchaseOrder, array $data): GoodsReceipt
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $purchaseOrder, $data) {
            $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if (! $po->status->isOpenForReceiving()) {
                throw new PurchaseOrderStateException(
                    'Only ordered or partial purchase orders can receive stock.',
                    'purchase_order_not_open',
                    409,
                    ['purchase_order_id' => $po->id, 'status' => $po->status->value],
                );
            }

            $lines = $data['items'] ?? [];
            if ($lines === []) {
                throw new InvalidInputException('items', 'At least one receive line is required.');
            }

            $poItems = PurchaseOrderItem::query()
                ->where('purchase_order_id', $po->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $receiptItems = [];
            foreach ($lines as $index => $line) {
                $itemId = (int) ($line['purchase_order_item_id'] ?? 0);
                $qty = (int) ($line['quantity'] ?? 0);
                $poItem = $poItems->get($itemId);

                if ($poItem === null) {
                    throw new InvalidInputException(
                        "items.{$index}.purchase_order_item_id",
                        'That line is not on this purchase order.',
                    );
                }
                if ($qty <= 0) {
                    throw new InvalidInputException("items.{$index}.quantity", 'Receive quantity must be greater than zero.');
                }

                $outstanding = $poItem->quantityOutstanding();
                if ($qty > $outstanding) {
                    throw new InvalidInputException(
                        "items.{$index}.quantity",
                        "Cannot receive more than {$outstanding} outstanding for this line.",
                    );
                }

                $receiptItems[] = [
                    'product_id' => $poItem->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $poItem->unit_cost,
                ];
                $poItem->quantity_received += $qty;
                $poItem->save();
            }

            $receipt = $this->receiveStock->execute($user, [
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'supplier_reference' => $data['supplier_reference'] ?? $po->po_no,
                'received_at' => $data['received_at'] ?? Carbon::now(),
                'notes' => $data['notes'] ?? "Against {$po->po_no}",
                'items' => $receiptItems,
            ]);

            $po->load('items');
            $allReceived = $po->items->every(fn (PurchaseOrderItem $i): bool => $i->quantity_received >= $i->quantity_ordered);
            $anyReceived = $po->items->contains(fn (PurchaseOrderItem $i): bool => $i->quantity_received > 0);

            $po->status = $allReceived
                ? PurchaseOrderStatus::Received
                : ($anyReceived ? PurchaseOrderStatus::Partial : $po->status);
            $po->save();

            return $receipt->load(['items.product', 'supplier', 'purchaseOrder', 'createdBy']);
        }));
    }
}
