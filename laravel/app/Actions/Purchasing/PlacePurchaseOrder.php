<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderStateException;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class PlacePurchaseOrder
{
    public function __construct(
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($purchaseOrder) {
            $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== PurchaseOrderStatus::Draft) {
                throw new PurchaseOrderStateException(
                    'Only draft purchase orders can be placed.',
                    'purchase_order_not_draft',
                    409,
                    ['purchase_order_id' => $po->id, 'status' => $po->status->value],
                );
            }

            if ($po->items()->count() === 0) {
                throw new PurchaseOrderStateException(
                    'A purchase order needs at least one line before it can be placed.',
                    'purchase_order_empty',
                    422,
                    ['purchase_order_id' => $po->id],
                );
            }

            $now = Carbon::now();
            $year = (int) $now->format('Y');
            $poNo = $this->numberSequence->format('PO', $year, $this->numberSequence->next('po', $year));

            $po->fill([
                'po_no' => $poNo,
                'status' => PurchaseOrderStatus::Ordered,
                'ordered_at' => $now,
            ]);
            $po->save();

            return $po->fresh(['items.product', 'supplier', 'createdBy']);
        }));
    }
}
