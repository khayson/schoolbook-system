<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }

    public function applyCredit(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }
}
