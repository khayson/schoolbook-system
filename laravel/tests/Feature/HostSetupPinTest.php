<?php

use App\Actions\Users\SetHostSetupPin;
use App\Models\User;

test('owner can set and verify a host setup pin via the api', function () {
    app(SetHostSetupPin::class)->execute('secret-host-pin', 'secret-host-pin');

    $this->postJson('/api/v1/auth/verify-setup-pin', ['pin' => 'secret-host-pin'])
        ->assertOk()
        ->assertJson(['valid' => true, 'code' => 'ok']);

    $this->postJson('/api/v1/auth/verify-setup-pin', ['pin' => 'wrong'])
        ->assertStatus(422)
        ->assertJson(['valid' => false, 'code' => 'invalid_pin']);
});

test('api reports when no host pin is configured', function () {
    app(SetHostSetupPin::class)->clear();

    $this->postJson('/api/v1/auth/verify-setup-pin', ['pin' => 'anything'])
        ->assertOk()
        ->assertJson(['valid' => false, 'code' => 'pin_not_configured']);
});

test('filament owner can clear the host pin', function () {
    $owner = User::factory()->owner()->create(['is_active' => true]);
    app(SetHostSetupPin::class)->execute('to-clear-now', 'to-clear-now');
    expect(app(SetHostSetupPin::class)->isConfigured())->toBeTrue();

    $this->actingAs($owner);
    app(SetHostSetupPin::class)->clear();
    expect(app(SetHostSetupPin::class)->isConfigured())->toBeFalse();
});
