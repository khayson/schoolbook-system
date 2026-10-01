<?php

use App\Models\Language;
use App\Models\Level;
use App\Models\LevelGroup;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('catalog seeders populate expected lookup counts', function () {
    $this->seed(DatabaseSeeder::class);

    expect(LevelGroup::query()->count())->toBe(4)
        ->and(Level::query()->count())->toBe(12)
        ->and(Subject::query()->count())->toBe(13)
        ->and(Language::query()->count())->toBe(13);
});

test('owner can crud a subject', function () {
    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $create = $this->withToken($token)->postJson('/api/v1/subjects', [
        'name' => 'Test Subject',
    ]);

    $create->assertCreated()
        ->assertJsonPath('data.name', 'Test Subject')
        ->assertJsonPath('data.slug', 'test-subject')
        ->assertJsonPath('data.is_active', true);

    $subjectId = $create->json('data.id');

    $this->withToken($token)->getJson('/api/v1/subjects/'.$subjectId)
        ->assertOk()
        ->assertJsonPath('data.name', 'Test Subject');

    $this->withToken($token)->putJson('/api/v1/subjects/'.$subjectId, [
        'name' => 'Updated Subject',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Updated Subject')
        ->assertJsonPath('data.slug', 'updated-subject');

    $this->withToken($token)->deleteJson('/api/v1/subjects/'.$subjectId)
        ->assertOk();

    expect(Subject::withTrashed()->find($subjectId)?->trashed())->toBeTrue();
});

test('lookup endpoints require authentication', function () {
    $this->getJson('/api/v1/subjects')->assertUnauthorized();
});
