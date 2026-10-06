<?php

namespace App\Policies;

use App\Models\DirectorySchool;
use App\Models\User;

/** The directory is for adding customers, which is the owner's. */
class DirectorySchoolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function addAsCustomer(User $user, DirectorySchool $school): bool
    {
        return $user->isOwner();
    }
}
