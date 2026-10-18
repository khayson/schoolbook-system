<?php

namespace App\Policies;

use App\Models\PriceRule;
use App\Models\User;

class PriceRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, PriceRule $priceRule): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, PriceRule $priceRule): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, PriceRule $priceRule): bool
    {
        return $user->isOwner();
    }
}
