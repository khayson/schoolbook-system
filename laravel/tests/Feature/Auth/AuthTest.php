<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

test('owner can login and receive a sanctum token', function () {
    $user = User::factory()->owner()->create([
        'email' => 'owner-login@schoolbook.test',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'pixel',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.role', 'owner')
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'is_active']]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

test('inactive or school users cannot login', function () {
    $school = User::factory()->school()->create([
        'email' => 'school@schoolbook.test',
        'password' => Hash::make('password'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $school->email,
        'password' => 'password',
    ])->assertForbidden()->assertJsonPath('code', 'forbidden');

    $inactive = User::factory()->owner()->inactive()->create([
        'email' => 'inactive@schoolbook.test',
        'password' => Hash::make('password'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $inactive->email,
        'password' => 'password',
    ])->assertForbidden();
});

test('me and logout require auth', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});

test('unauthenticated access is rejected', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

test('account status returns pending or active with a clear message', function () {
    $pending = User::factory()->owner()->inactive()->create([
        'email' => 'pending-status@schoolbook.test',
        'registered_at' => now()->subDay(),
    ]);

    $this->getJson('/api/v1/auth/status?email='.$pending->email)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.name', $pending->name)
        ->assertJsonPath('data.message', 'Your account is still under review. An admin needs to activate it — usually within 1–2 business days.')
        ->assertJsonStructure(['data' => ['status', 'message', 'name', 'registered_at']]);

    expect($this->getJson('/api/v1/auth/status?email='.$pending->email)->json('data.registered_at'))
        ->toBeString()
        ->not->toBeEmpty();

    $active = User::factory()->owner()->create([
        'email' => 'active-status@schoolbook.test',
        'registered_at' => now()->subDays(2),
    ]);

    $this->getJson('/api/v1/auth/status?email='.$active->email)
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonFragment(['message' => 'Your account is active. You can sign in now.']);
});

test('account status reports unknown email clearly', function () {
    $this->getJson('/api/v1/auth/status?email=nobody@schoolbook.test')
        ->assertNotFound()
        ->assertJsonPath('code', 'account_not_found')
        ->assertJsonPath('message', 'No account was found for this email.');
});

test('account status requires a valid email', function () {
    $this->getJson('/api/v1/auth/status')
        ->assertUnprocessable();
});
