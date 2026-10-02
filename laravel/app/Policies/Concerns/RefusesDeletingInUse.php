<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Catalog records that anything refers to are retired with is_active, never deleted:
 * deleting them would either hit a foreign key (raw database error) or hide history.
 * Force delete is never offered.
 */
trait RefusesDeletingInUse
{
    abstract protected function isInUse(mixed $model): bool;

    public function delete(User $user, mixed $model): Response
    {
        if (! $user->isOwner()) {
            return Response::deny();
        }

        return $this->isInUse($model)
            ? Response::deny('This record is in use. Deactivate it instead of deleting it.')
            : Response::allow();
    }

    public function deleteAny(User $user): bool
    {
        // No bulk delete: each record is checked on its own.
        return false;
    }

    public function restore(User $user, mixed $model): bool
    {
        return $user->isOwner();
    }

    public function forceDelete(User $user, mixed $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
