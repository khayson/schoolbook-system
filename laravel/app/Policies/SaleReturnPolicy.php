<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;

class SaleReturnPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, SaleReturn $saleReturn): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function confirm(User $user, SaleReturn $saleReturn): bool
    {
        return $user->isOwner();
    }

    public function void(User $user, SaleReturn $saleReturn): bool
    {
        return $user->isOwner();
    }
}
