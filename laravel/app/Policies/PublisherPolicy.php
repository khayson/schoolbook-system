<?php

namespace App\Policies;

use App\Models\Publisher;
use App\Models\User;

class PublisherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Publisher $publisher): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Publisher $publisher): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Publisher $publisher): bool
    {
        return $user->isOwner();
    }
}
