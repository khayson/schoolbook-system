<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderStateException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class CancelPurchaseOrder
{
    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($purchaseOrder) {
            $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                throw new PurchaseOrderStateException(
                    'Only draft or ordered purchase orders with no receipts can be cancelled.',
                    'purchase_order_not_cancellable',
                    409,
                    ['purchase_order_id' => $po->id, 'status' => $po->status->value],
                );
            }

            if ($po->items()->where('quantity_received', '>', 0)->exists()) {
                throw new PurchaseOrderStateException(
                    'A purchase order with received quantities cannot be cancelled.',
                    'purchase_order_has_receipts',
                    409,
                    ['purchase_order_id' => $po->id],
                );
            }

            $po->status = PurchaseOrderStatus::Cancelled;
            $po->save();

            return $po->fresh(['items.product', 'supplier', 'createdBy']);
        }));
    }
}
