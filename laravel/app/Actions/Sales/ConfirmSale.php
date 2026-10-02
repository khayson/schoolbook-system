<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\LocksSaleRows;
use App\DTOs\Pricing\AppliedRuleSummary;
use App\DTOs\Pricing\PricedOrder;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\CreditLimitExceededException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInputException;
use App\Exceptions\PriceChangedException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\NumberSequenceService;
use App\Services\PricingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Draft -> confirmed (spec 9.1). One transaction; any exception writes nothing.
 *
 * Locks (global order, spec 5.14): customer -> sale -> items -> products (sorted) ->
 * invoice sequence LAST. Then: reprice and compare -> stock check -> credit check ->
 * sale_out movements -> verified item snapshots -> invoice number -> sale fields.
 * The reload for the response happens after commit so no lock is held for it.
 */
class ConfirmSale
{
    use LocksSaleRows;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly NumberSequenceService $numberSequence,
        private readonly CauserResolver $causer,
    ) {}

    /**
     * @param  array{due_date?: string|\DateTimeInterface|null, override_credit_limit?: bool}  $options
     */
    public function execute(User $user, Sale $sale, array $options = []): Sale
    {
        $confirmed = $this->causer->withCauser($user, fn () => DB::transaction(function () use ($user, $sale, $options) {
            // 1. Customer, then sale, then items (locking reads; see LocksSaleRows).
            [$customer, $locked] = $this->lockCustomerAndSale($sale);
            $locked->assertCanTransitionTo(SaleStatus::Confirmed, 'confirm');

            $items = $this->lockItems($locked);
            if ($items->isEmpty()) {
                throw new InvalidInputException('items', 'A sale needs at least one line item to be confirmed.');
            }

            // 2. Products in ascending id order. unit_cost comes from these locked rows.
            $products = $this->lockProducts($items->pluck('product_id'));

            // 3. Reprice with the sale date (first plain reads happen inside PricingService).
            //    Inactive or deleted products and customers fail as 422s keyed to the line/field.
            if ($customer->trashed() || ! $customer->is_active) {
                throw new InvalidInputException('customer_id', 'The customer on this sale is inactive or no longer exists.');
            }

            $priced = $this->pricing->priceLines($customer, $this->pricingInput($items), $locked->sale_date);

            if (! $this->matchesDraft($locked, $items, $priced)) {
                throw new PriceChangedException($priced);
            }

            // 4. Stock, summed per product, all shortages reported together.
            $this->assertStockAvailable($priced, $products);

            // 5. Credit limit (customer row already locked in step 1).
            $this->checkCreditLimit($locked, $customer, $priced->total, $user, (bool) ($options['override_credit_limit'] ?? false));

            $confirmedAt = Carbon::now();

            // 6. sale_out movements, one per line, running balance per product.
            $balances = array_map(fn (Product $product): int => (int) $product->stock_on_hand, $products);

            foreach ($items->values() as $index => $item) {
                $line = $priced->lines[$index];
                $product = $products[$line->productId];
                $balances[$product->id] -= $line->quantity;

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'type' => StockMovementType::SaleOut,
                    'quantity' => -$line->quantity,
                    'balance_after' => $balances[$product->id],
                    'unit_cost' => (int) $product->cost_price,
                    'reference_type' => $locked->getMorphClass(),
                    'reference_id' => $locked->id,
                    'note' => null,
                    'user_id' => $user->id,
                    'occurred_at' => $confirmedAt,
                ]);

                // Verified snapshot. The sale row is still draft here, so the SaleItem guard allows it.
                $item->fill([
                    'product_title' => $line->productTitle,
                    'base_price' => $line->basePrice,
                    'unit_price' => $line->unitPrice,
                    'discount_amount' => $line->discountAmount,
                    'tax_amount' => $line->taxAmount,
                    'line_total' => $line->lineTotal,
                    'unit_cost' => (int) $product->cost_price,
                    'applied_rules' => array_map(
                        fn (AppliedRuleSummary $rule): array => $rule->toArray(),
                        $line->appliedRules,
                    ),
                ])->save();
            }

            foreach ($products as $product) {
                $product->stock_on_hand = $balances[$product->id];
                $product->save();
            }

            // 7. Invoice number last; year from the confirmation date (spec 5.15).
            $year = (int) $confirmedAt->format('Y');
            $invoiceNo = $this->numberSequence->format('INV', $year, $this->numberSequence->next('inv', $year));

            $locked->fill([
                'invoice_no' => $invoiceNo,
                'status' => SaleStatus::Confirmed,
                'payment_status' => PaymentStatus::derive($priced->total, 0),
                'due_date' => $this->dueDate($locked, $options, $confirmedAt),
                'subtotal' => $priced->subtotal,
                'discount_total' => $priced->discountTotal,
                'tax_total' => $priced->taxTotal,
                'total' => $priced->total,
                'confirmed_by' => $user->id,
                'confirmed_at' => $confirmedAt,
            ]);
            $locked->amount_paid = 0;
            $locked->balance_due = $priced->total;
            $locked->save();

            // Cached receivable, on the customer row locked in step 1.
            $customer->outstanding_balance += $priced->total;
            $customer->save();

            return $locked;
        }));

        return $confirmed->fresh(['customer', 'items.product', 'createdBy']);
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @return list<array{product_id: int, quantity: int, override_unit_price: int|null, override_reason: string|null}>
     */
    private function pricingInput(Collection $items): array
    {
        return $items->map(fn (SaleItem $item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'override_unit_price' => $item->is_price_overridden ? $item->unit_price : null,
            'override_reason' => $item->is_price_overridden ? $item->override_reason : null,
        ])->values()->all();
    }

    /**
     * Same lines (product, quantity, unit price, line total) in the same order, and
     * the same order totals. A changed base price under an unchanged override is not a
     * price change; the verified base price is still written to the snapshot.
     *
     * @param  Collection<int, SaleItem>  $items
     */
    private function matchesDraft(Sale $sale, Collection $items, PricedOrder $priced): bool
    {
        if (count($priced->lines) !== $items->count()) {
            return false;
        }

        foreach ($items->values() as $index => $item) {
            $line = $priced->lines[$index];

            if ($line->productId !== $item->product_id
                || $line->quantity !== $item->quantity
                || $line->unitPrice !== $item->unit_price
                || $line->lineTotal !== $item->line_total) {
                return false;
            }
        }

        return $priced->subtotal === $sale->subtotal
            && $priced->discountTotal === $sale->discount_total
            && $priced->taxTotal === $sale->tax_total
            && $priced->total === $sale->total;
    }

    /**
     * @param  array<int, Product>  $products
     */
    private function assertStockAvailable(PricedOrder $priced, array $products): void
    {
        if (Setting::getValue('allow_negative_stock', false)) {
            return;
        }

        $shortages = [];
        foreach ($priced->quantitiesByProduct() as $productId => $requested) {
            $product = $products[$productId];
            $available = (int) $product->stock_on_hand;

            if ($requested > $available) {
                $shortages[] = [
                    'product_id' => $product->id,
                    'sku' => (string) $product->sku,
                    'title' => (string) $product->title,
                    'requested' => $requested,
                    'available' => $available,
                ];
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStockException($shortages);
        }
    }

    /**
     * Warning, not a block (spec 9.1): the client resends with override_credit_limit.
     * Reads the cached outstanding_balance from the customer row this transaction holds
     * locked; every money action maintains that cache under the same lock.
     */
    private function checkCreditLimit(Sale $sale, Customer $customer, int $total, User $user, bool $override): void
    {
        if ($customer->credit_limit === null) {
            return;
        }

        $outstanding = (int) $customer->outstanding_balance;

        if ($outstanding + $total <= $customer->credit_limit) {
            return;
        }

        if (! $override) {
            throw new CreditLimitExceededException($customer->credit_limit, $outstanding, $total);
        }

        activity()
            ->performedOn($sale)
            ->causedBy($user)
            ->event('credit_limit_overridden')
            ->withProperties([
                'customer_id' => $customer->id,
                'credit_limit' => $customer->credit_limit,
                'outstanding' => $outstanding,
                'sale_total' => $total,
                'projected_balance' => $outstanding + $total,
            ])
            ->log('credit_limit_overridden');
    }

    /**
     * Request due_date, else the draft's, else confirmation date + payment terms.
     *
     * @param  array{due_date?: string|\DateTimeInterface|null}  $options
     */
    private function dueDate(Sale $sale, array $options, Carbon $confirmedAt): Carbon
    {
        if (! empty($options['due_date'])) {
            return Carbon::parse($options['due_date'])->startOfDay();
        }

        if ($sale->due_date !== null) {
            return $sale->due_date->copy();
        }

        $terms = max(0, (int) Setting::getValue('default_payment_terms_days', 30));

        return $confirmedAt->copy()->startOfDay()->addDays($terms);
    }
}
