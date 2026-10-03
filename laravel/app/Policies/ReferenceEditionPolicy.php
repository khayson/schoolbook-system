<?php

namespace App\Policies;

use App\Models\ReferenceEdition;
use App\Models\User;

/**
 * Imports come from the reference:import command; the admin reviews, publishes or
 * discards them. Editions are never edited directly or deleted.
 */
class ReferenceEditionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, ReferenceEdition $edition): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Review decisions and fixes on a draft's rows. */
    public function review(User $user, ReferenceEdition $edition): bool
    {
        return $user->isOwner() && $edition->isDraft();
    }

    public function publish(User $user, ReferenceEdition $edition): bool
    {
        return $user->isOwner() && $edition->isDraft();
    }

    public function discard(User $user, ReferenceEdition $edition): bool
    {
        return $user->isOwner() && $edition->isDraft();
    }

    public function update(User $user, ReferenceEdition $edition): bool
    {
        return false;
    }

    public function delete(User $user, ReferenceEdition $edition): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
