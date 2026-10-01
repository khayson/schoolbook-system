<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Sale $sale): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Sale $sale): bool
    {
        return $user->isOwner();
    }

    /**
     * Financial records are never deleted (spec rule 5).
     */
    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }
}
