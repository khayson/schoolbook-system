<?php

use App\Enums\UserRole;
use App\Models\NumberSequence;
use App\Models\Setting;
use App\Models\User;
use App\Services\NumberSequenceService;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Hash;

test('users table has role customer_id and is_active', function () {
    $user = User::factory()->owner()->create();

    expect($user->role)->toBe(UserRole::Owner)
        ->and($user->customer_id)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->isOwner())->toBeTrue();
});

test('owner seeder creates active owner', function () {
    $this->seed(OwnerSeeder::class);

    $owner = User::query()->where('email', 'owner@schoolbook.test')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->role)->toBe(UserRole::Owner)
        ->and($owner->is_active)->toBeTrue()
        ->and(Hash::check('password', $owner->password))->toBeTrue();
});

test('settings seeder stores default keys', function () {
    $this->seed(SettingsSeeder::class);

    expect(Setting::getValue('business_name'))->toBe('Schoolbook Supply')
        ->and(Setting::getValue('allow_negative_stock'))->toBeFalse()
        ->and(Setting::getValue('tax_enabled'))->toBeFalse()
        ->and(Setting::getValue('default_payment_terms_days'))->toBe(30);
});

test('number sequence increments', function () {
    $service = app(NumberSequenceService::class);

    expect($service->next('grn', 2026))->toBe(1)
        ->and($service->next('grn', 2026))->toBe(2)
        ->and($service->format('GRN', 2026, 2))->toBe('GRN-2026-000002');

    expect(NumberSequence::query()->where('key', 'grn')->where('year', 2026)->value('last_number'))->toBe(2);
});
