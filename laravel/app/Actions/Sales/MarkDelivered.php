<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\LocksSaleRows;
use App\Enums\SaleStatus;
use App\Exceptions\SaleNotEditableException;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sets delivered_at on a confirmed sale. Delivery is not a status (spec 9.1).
 * Idempotent: an already-delivered sale keeps its original delivered_at.
 */
class MarkDelivered
{
    use LocksSaleRows;

    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, Sale $sale): Sale
    {
        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($sale) {
            $locked = $this->lockSale($sale->id);

            if ($locked->status !== SaleStatus::Confirmed) {
                throw new SaleNotEditableException($locked, 'deliver');
            }

            if ($locked->delivered_at === null) {
                $locked->update(['delivered_at' => Carbon::now()]);
            }

            return $locked->fresh()->load(['customer', 'items.product', 'createdBy']);
        }));
    }
}
