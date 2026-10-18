<?php

namespace App\Actions\Sales;

use App\Actions\Payments\Concerns\LocksLedgerRows;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\RestockCondition;
use App\Enums\SaleReturnStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidInputException;
use App\Exceptions\SaleReturnStateException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AllocationLedger;
use App\Services\NumberSequenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Confirm a draft sale return: restock good lines, audit-only damage lines,
 * and credit the customer via a return_credit payment (source sale first, then FIFO).
 *
 * Locks: customer → sales (source + open) → return items → products → sequences last.
 */
class ConfirmSaleReturn
{
    use LocksLedgerRows;

    public function __construct(
        private readonly AllocationLedger $ledger,
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    public function execute(User $user, SaleReturn $saleReturn, ?string $reason = null): SaleReturn
    {
        $confirmed = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $saleReturn, $reason) {
            $customer = $this->lockPayingCustomer($saleReturn->customer_id);

            // Lock every sale that may receive the credit before touching products/sequences.
            $allSaleIds = Sale::query()
                ->where('customer_id', $customer->id)
                ->where(function ($q) use ($saleReturn) {
                    $q->whereKey($saleReturn->sale_id)
                        ->orWhere(function ($open) {
                            $open->where('status', SaleStatus::Confirmed)->where('balance_due', '>', 0);
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $sales = $this->lockCustomerSalesById($customer, $allSaleIds);
            $sale = $sales->get($saleReturn->sale_id);
            if ($sale === null) {
                throw new InvalidInputException('sale_id', 'The source sale was not found for this customer.');
            }

            $locked = SaleReturn::query()->whereKey($saleReturn->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SaleReturnStatus::Draft) {
                throw new SaleReturnStateException(
                    'Only draft returns can be confirmed.',
                    'sale_return_not_draft',
                    409,
                    ['sale_return_id' => $locked->id, 'status' => $locked->status->value],
                );
            }

            if ($sale->status !== SaleStatus::Confirmed) {
                throw new SaleReturnStateException(
                    'The source sale is no longer confirmed.',
                    'sale_not_returnable',
                    409,
                    ['sale_id' => $sale->id, 'status' => $sale->status->value],
                );
            }

            $reasonText = trim((string) ($reason ?? $locked->reason ?? ''));
            if ($reasonText === '') {
                throw new InvalidInputException('reason', 'A reason is required to confirm a return.');
            }

            $items = SaleReturnItem::query()
                ->where('sale_return_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new InvalidInputException('items', 'A return needs at least one line item.');
            }

            SaleItem::query()
                ->where('sale_id', $sale->id)
                ->whereIn('id', $items->pluck('sale_item_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertQuantitiesStillReturnable($sale, $items);

            $productIds = $items->pluck('product_id')->unique()->sort()->values()->all();
            /** @var array<int, Product> $products */
            $products = [];
            foreach ($productIds as $productId) {
                $products[$productId] = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            }

            $now = Carbon::now();
            $year = (int) $now->format('Y');
            $returnNo = $this->numberSequence->format('SRN', $year, $this->numberSequence->next('srn', $year));

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                if ($item->restock_condition === RestockCondition::Good) {
                    $balanceAfter = $product->stock_on_hand + $item->quantity;
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'type' => StockMovementType::ReturnIn,
                        'quantity' => $item->quantity,
                        'balance_after' => $balanceAfter,
                        'unit_cost' => $item->unit_cost,
                        'reference_type' => $locked->getMorphClass(),
                        'reference_id' => $locked->id,
                        'note' => "Return {$returnNo} (good)",
                        'user_id' => $user->id,
                        'occurred_at' => $now,
                    ]);
                    $product->stock_on_hand = $balanceAfter;
                } else {
                    // Audit-only: goods returned unsellable — quantity recorded, stock unchanged.
                    StockMovement::query()->create([
                        'product_id' => $product->id,
                        'type' => StockMovementType::Damage,
                        'quantity' => $item->quantity,
                        'balance_after' => $product->stock_on_hand,
                        'unit_cost' => $item->unit_cost,
                        'reference_type' => $locked->getMorphClass(),
                        'reference_id' => $locked->id,
                        'note' => "Return {$returnNo} damaged (audit only; stock unchanged)",
                        'user_id' => $user->id,
                        'occurred_at' => $now,
                    ]);
                }
            }

            foreach ($products as $product) {
                $product->save();
            }

            $locked->fill([
                'return_no' => $returnNo,
                'status' => SaleReturnStatus::Confirmed,
                'reason' => $reasonText,
                'confirmed_at' => $now,
            ]);
            $locked->save();

            $this->creditCustomer($user, $customer, $sale, $sales, $locked, $now);

            return $locked;
        }));

        return $confirmed->fresh(['items.product', 'sale', 'customer', 'createdBy']);
    }

    /**
     * @param  Collection<int, SaleReturnItem>  $items
     */
    private function assertQuantitiesStillReturnable(Sale $sale, Collection $items): void
    {
        $returned = SaleReturnItem::query()
            ->whereHas('saleReturn', fn ($q) => $q
                ->where('sale_id', $sale->id)
                ->where('status', SaleReturnStatus::Confirmed))
            ->selectRaw('sale_item_id, SUM(quantity) as qty')
            ->groupBy('sale_item_id')
            ->pluck('qty', 'sale_item_id');

        $saleQtys = SaleItem::query()->where('sale_id', $sale->id)->pluck('quantity', 'id');

        foreach ($items as $item) {
            $available = max(0, (int) ($saleQtys[$item->sale_item_id] ?? 0) - (int) ($returned[$item->sale_item_id] ?? 0));
            if ($item->quantity > $available) {
                throw new InvalidInputException(
                    'items',
                    "Cannot return more than {$available} of sale item {$item->sale_item_id}.",
                );
            }
        }
    }

    /**
     * @param  Collection<int, Sale>  $sales  locked sales keyed by id
     */
    private function creditCustomer(
        User $user,
        Customer $customer,
        Sale $sourceSale,
        Collection $sales,
        SaleReturn $return,
        Carbon $now,
    ): void {
        $amount = (int) $return->total;
        if ($amount <= 0) {
            return;
        }

        $receiptYear = (int) $now->format('Y');
        $receiptNo = $this->numberSequence->format('RCT', $receiptYear, $this->numberSequence->next('rct', $receiptYear));

        $payment = new Payment([
            'receipt_no' => $receiptNo,
            'customer_id' => $customer->id,
            'amount' => $amount,
            'method' => PaymentMethod::ReturnCredit,
            'reference' => $return->return_no,
            'paid_at' => $now,
            'status' => PaymentRecordStatus::Valid,
            'notes' => "Credit for return {$return->return_no}",
            'received_by' => $user->id,
        ]);
        $payment->unallocated_amount = $amount;
        $payment->save();

        $customer->credit_balance += $amount;
        $customer->save();

        $remaining = $amount;
        $plan = [];

        if ($sourceSale->balance_due > 0) {
            $take = min($remaining, $sourceSale->balance_due);
            $plan[$sourceSale->id] = $take;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $open = $sales
                ->filter(fn (Sale $s): bool => $s->id !== $sourceSale->id
                    && $s->status === SaleStatus::Confirmed
                    && $s->balance_due > 0)
                ->sort(fn (Sale $a, Sale $b): int => [
                    $a->due_date === null ? 1 : 0, $a->due_date?->toDateString(), $a->sale_date->toDateString(), $a->id,
                ] <=> [
                    $b->due_date === null ? 1 : 0, $b->due_date?->toDateString(), $b->sale_date->toDateString(), $b->id,
                ])
                ->values();

            foreach ($this->planOldestFirst($open, $remaining) as $saleId => $alloc) {
                $plan[$saleId] = ($plan[$saleId] ?? 0) + $alloc;
            }
        }

        foreach ($plan as $saleId => $allocation) {
            $this->ledger->allocate($customer, $payment, $sales[$saleId], $allocation, $user);
        }
    }
}
