<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function void(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    public function receipt(User $user, Payment $payment): bool
    {
        return $user->isOwner();
    }

    /**
     * Financial records are never deleted (spec rule 5).
     */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
