<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStaffUser
{
    /**
     * Create an active owner who can use Filament and the phone API.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null, is_active?: bool}  $data
     */
    public function execute(array $data): User
    {
        if (User::query()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['An account with this email already exists.'],
            ]);
        }

        return DB::transaction(function () use ($data) {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->phone = $data['phone'] ?? null;
            $user->role = UserRole::Owner;
            $user->customer_id = null;
            $user->is_active = (bool) ($data['is_active'] ?? true);
            $user->email_verified_at = now();
            $user->save();

            return $user;
        });
    }
}
