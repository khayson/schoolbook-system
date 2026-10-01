<?php

namespace App\Policies;

use App\Models\User;

class LevelGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }
}
