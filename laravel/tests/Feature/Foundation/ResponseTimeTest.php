<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

test('every response carries X-Response-Time in milliseconds', function () {
    $this->get('/up')->assertOk()->assertHeader('X-Response-Time');

    Sanctum::actingAs(User::factory()->owner()->create());
    $header = $this->getJson('/api/v1/auth/me')->assertOk()->headers->get('X-Response-Time');

    expect($header)->toMatch('/^\d+ms$/');
});

test('a request over the threshold is logged with route, status, duration and memory', function () {
    config(['app.slow_request_ms' => -1]);
    Log::spy();
    Sanctum::actingAs($owner = User::factory()->owner()->create());

    $this->getJson('/api/v1/auth/me')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Slow request'
        && $context['method'] === 'GET'
        && $context['path'] === '/api/v1/auth/me'
        && $context['route'] === 'api/v1/auth/me'
        && $context['status'] === 200
        && is_int($context['duration_ms'])
        && is_float($context['memory_peak_mb'])
        && $context['user_id'] === $owner->id);
});

test('requests under the threshold are not logged', function () {
    config(['app.slow_request_ms' => 600000]);
    Log::spy();

    $this->get('/up')->assertOk();

    Log::shouldNotHaveReceived('warning');
});
