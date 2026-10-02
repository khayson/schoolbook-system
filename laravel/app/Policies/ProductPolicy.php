<?php

namespace App\Policies;

use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\Concerns\RefusesDeletingInUse;

class ProductPolicy
{
    use RefusesDeletingInUse;

    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Product $product): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isOwner();
    }

    /**
     * delete / deleteAny / restore / forceDelete come from RefusesDeletingInUse.
     */
    protected function isInUse(mixed $model): bool
    {
        return StockMovement::query()->where('product_id', $model->id)->exists()
            || SaleItem::query()->where('product_id', $model->id)->exists()
            || GoodsReceiptItem::query()->where('product_id', $model->id)->exists();
    }
}
