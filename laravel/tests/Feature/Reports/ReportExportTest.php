<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    ReportsFixture::build($this->owner);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

test('sales summary csv export downloads tabular data', function () {
    $response = $this->withToken($this->token)
        ->get('/api/v1/reports/sales-summary?from=2026-06-01&to=2026-06-30&group_by=day&format=csv');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    $body = $response->streamedContent();
    expect($body)->toContain('period')
        ->and($body)->toContain('2026-06-10');
});

test('low stock json remains default without format', function () {
    $this->withToken($this->token)
        ->getJson('/api/v1/reports/low-stock')
        ->assertOk()
        ->assertJsonStructure(['data']);
});
