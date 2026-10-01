<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateOwnerCommand extends Command
{
    protected $signature = 'owner:create
                            {--name= : Owner display name}
                            {--email= : Owner email}
                            {--password= : Owner password (prefer prompting)}';

    protected $description = 'Create or update the Filament/API owner account';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', default: 'Owner', required: true);
        $email = $this->option('email') ?: text('Email', default: 'owner@schoolbook.test', required: true);
        $plain = $this->option('password') ?: password('Password', required: true);

        if (strlen((string) $plain) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($plain),
                'role' => UserRole::Owner,
                'customer_id' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->info("Owner ready: {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }
}
