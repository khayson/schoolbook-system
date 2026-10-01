<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('OwnerSeeder skipped outside local/testing. Use: php artisan owner:create');

            return;
        }

        $email = env('OWNER_EMAIL', 'owner@schoolbook.test');
        $password = env('OWNER_PASSWORD', 'password');
        $name = env('OWNER_NAME', 'Owner');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => UserRole::Owner,
                'customer_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
