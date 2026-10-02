<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\Publisher;
use App\Models\User;
use App\Policies\Concerns\RefusesDeletingInUse;

class PublisherPolicy
{
    use RefusesDeletingInUse;

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

    /**
     * delete / deleteAny / restore / forceDelete come from RefusesDeletingInUse.
     */
    protected function isInUse(mixed $model): bool
    {
        return Product::withTrashed()->where('publisher_id', $model->id)->exists();
    }
}
