<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterUserAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User;
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = $data['password'];
            $user->role = UserRole::Owner;
            $user->is_active = false;
            $user->registered_at = now();

            if (isset($data['phone'])) {
                $user->phone = $data['phone'];
            }

            if (isset($data['google_id'])) {
                $user->google_id = $data['google_id'];
            }

            $user->save();

            return $user;
        });
    }
}
