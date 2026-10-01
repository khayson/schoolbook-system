<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Subject $subject): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Subject $subject): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Subject $subject): bool
    {
        return $user->isOwner();
    }
}
