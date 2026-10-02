<?php

namespace App\Filament\Support;

use App\Models\User;
use Filament\Facades\Filament;
use LogicException;

/**
 * Filament pages have no getUser(); Actions need the acting User model.
 * (Phase 1/2A pages called $this->getUser() without this and failed at runtime;
 * found when 2D.1 added the first tests that submit those pages.)
 */
trait InteractsWithCurrentUser
{
    protected function getUser(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('No authenticated user.');
        }

        return $user;
    }
}
