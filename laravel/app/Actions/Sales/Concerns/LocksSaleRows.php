<?php

namespace App\Actions\Sales\Concerns;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lock order for sale state changes (spec 5.14):
 *   sale row -> its items -> products (sorted by id) -> customer -> invoice sequence (last).
 *
 * Every read here is a locking read. That matters under MySQL REPEATABLE READ: the
 * transaction's snapshot is taken at its first *non-locking* read, so doing all locking
 * reads first means later plain reads (PricingService, settings) see data at least as
 * new as the rows we hold.
 */
trait LocksSaleRows
{
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
