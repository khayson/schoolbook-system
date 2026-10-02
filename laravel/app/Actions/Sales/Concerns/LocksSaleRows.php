<?php

namespace App\Actions\Sales\Concerns;

use App\Exceptions\SaleStateConflictException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Global lock order for money/stock actions (spec 5.14):
 *   customer -> sale(s) -> sale items -> products (ascending id) -> invoice sequence (last).
 *
 * Every money action locks the customer first, so those actions are serialized per
 * customer and the order of sale locks among them cannot cycle. Stock-only actions
 * (receipts, adjustments) lock products only, which sit after customer and sale.
 *
 * Every read here is a locking read. Under MySQL REPEATABLE READ the transaction's
 * snapshot is taken at its first *non-locking* read, so taking all locks first means
 * later plain reads (PricingService, settings) see data at least as new as the rows held.
 */
trait LocksSaleRows
{
    /**
     * Locks the customer, then the sale. The customer id comes from the sale the request
     * loaded outside the transaction (no plain read before the locks). If the locked sale
     * no longer belongs to that customer (a draft was reassigned meanwhile), nothing has
     * been written yet: 409 sale_state_conflict, client reloads and retries.
     *
     * @return array{0: Customer, 1: Sale}
     */
    private function lockCustomerAndSale(Sale $requested): array
    {
        $customer = $this->lockCustomer($requested->customer_id);
        $sale = Sale::query()->whereKey($requested->id)->lockForUpdate()->firstOrFail();

        if ($sale->customer_id !== $customer->id) {
            throw new SaleStateConflictException($sale);
        }

        return [$customer, $sale];
    }

    /**
     * For actions with no customer or stock effect (cancel, deliver): the sale row alone,
     * which is consistent with the global order because nothing is locked after it.
     */
    private function lockSale(int $saleId): Sale
    {
        return Sale::query()->whereKey($saleId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Collection<int, SaleItem>
     */
    private function lockItems(Sale $sale): Collection
    {
        return SaleItem::query()
            ->where('sale_id', $sale->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Locks each distinct product once, ascending id. Includes soft-deleted products so a
     * void can still restore their stock; confirm rejects them through PricingService.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, Product> keyed by id, ascending
     */
    private function lockProducts(iterable $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', [...$productIds])));
        sort($ids);

        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = Product::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
        }

        return $locked;
    }

    private function lockCustomer(int $customerId): Customer
    {
        return Customer::withTrashed()->whereKey($customerId)->lockForUpdate()->firstOrFail();
    }
}
