<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\LocksSaleRows;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Draft -> cancelled. No stock or money effect: drafts never reserved anything.
 */
class CancelSale
{
    use LocksSaleRows;

    public function __construct(
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, Sale $sale, ?string $reason = null): Sale
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return $this->causer->withCauser($user, fn () => DB::transaction(function () use ($sale, $reason) {
            $locked = $this->lockSale($sale->id);
            $locked->assertCanTransitionTo(SaleStatus::Cancelled, 'cancel');

            $locked->update([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => Carbon::now(),
                'cancel_reason' => $reason,
            ]);

            return $locked->fresh()->load(['customer', 'items.product', 'createdBy']);
        }));
    }
}
