<?php

namespace App\Policies;

use App\Models\ReferenceBook;
use App\Models\User;

/**
 * The approved list is read-only: it changes only by publishing a reviewed import.
 */
class ReferenceBookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, ReferenceBook $book): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReferenceBook $book): bool
    {
        return false;
    }

    public function delete(User $user, ReferenceBook $book): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
