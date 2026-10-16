<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UpdateStaffUser
{
    /**
     * @param  array{name?: string, email?: string, password?: string|null, phone?: string|null, is_active?: bool}  $data
     */
    public function execute(User $actor, User $user, array $data): User
    {
        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (User::query()->where('email', $data['email'])->whereKeyNot($user->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['An account with this email already exists.'],
                ]);
            }
        }

        $becomingInactive = array_key_exists('is_active', $data)
            && $data['is_active'] === false
            && $user->is_active;

        if ($becomingInactive && $actor->is($user)) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        if ($becomingInactive && $user->isOwner()) {
            $otherActiveOwners = User::query()
                ->where('role', UserRole::Owner)
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->exists();

            if (! $otherActiveOwners) {
                throw new RuntimeException('Cannot deactivate the last active owner.');
            }
        }

        return DB::transaction(function () use ($user, $data, $becomingInactive) {
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            if (array_key_exists('email', $data)) {
                $user->email = $data['email'];
            }
            if (array_key_exists('phone', $data)) {
                $user->phone = $data['phone'];
            }
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if (array_key_exists('is_active', $data)) {
                $user->is_active = (bool) $data['is_active'];
            }

            $user->save();

            if ($becomingInactive) {
                $user->tokens()->delete();
            }

            return $user->refresh();
        });
    }
}
