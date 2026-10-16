<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() && $user->is_active;
    }

    public function view(User $user, User $model): bool
    {
        return $user->isOwner() && $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->is_active;
    }

    public function update(User $user, User $model): bool
    {
        return $user->isOwner() && $user->is_active;
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
