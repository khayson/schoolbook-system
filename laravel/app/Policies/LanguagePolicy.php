<?php

namespace App\Policies;

use App\Models\Language;
use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\RefusesDeletingInUse;

class LanguagePolicy
{
    use RefusesDeletingInUse;

    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Language $language): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Language $language): bool
    {
        return $user->isOwner();
    }

    /**
     * delete / deleteAny / restore / forceDelete come from RefusesDeletingInUse.
     */
    protected function isInUse(mixed $model): bool
    {
        return Product::withTrashed()->where('language_id', $model->id)->exists();
    }
}
