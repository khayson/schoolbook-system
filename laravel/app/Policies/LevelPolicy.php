<?php

namespace App\Policies;

use App\Models\Level;
use App\Models\User;

class LevelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Level $level): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Level $level): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Level $level): bool
    {
        return $user->isOwner();
    }
}
