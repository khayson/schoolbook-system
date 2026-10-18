<?php

namespace App\Policies;

use App\Models\StockExchange;
use App\Models\User;

class StockExchangePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, StockExchange $stockExchange): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, StockExchange $stockExchange): bool
    {
        return $user->isOwner();
    }

    public function confirm(User $user, StockExchange $stockExchange): bool
    {
        return $user->isOwner();
    }

    public function cancel(User $user, StockExchange $stockExchange): bool
    {
        return $user->isOwner();
    }

    public function void(User $user, StockExchange $stockExchange): bool
    {
        return $user->isOwner();
    }
}
