<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;

/** Stock-take is the owner's: it rewrites stock. */
class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, StockCount $count): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, StockCount $count): bool
    {
        return $user->isOwner();
    }

    public function apply(User $user, StockCount $count): bool
    {
        return $user->isOwner();
    }

    public function cancel(User $user, StockCount $count): bool
    {
        return $user->isOwner();
    }
}
