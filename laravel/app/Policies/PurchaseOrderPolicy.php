<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isOwner();
    }

    public function place(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isOwner();
    }

    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isOwner();
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isOwner();
    }
}
