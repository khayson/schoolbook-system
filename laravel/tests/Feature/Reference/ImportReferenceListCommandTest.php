<?php

use App\Models\ReferenceEdition;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReferenceListFixture;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

test('reference:import reads a PDF into a draft for review', function () {
    User::factory()->owner()->create(['email' => 'owner@example.test']);
    $path = ReferenceListFixture::standard()->toPdf(storage_path('framework/testing/synthetic-reference-list.pdf'));

    $this->artisan('reference:import', ['path' => $path, '--edition' => 'Synthetic list', '--published' => '2024-12-01', '--source-url' => 'https://example.test/list.pdf'])
        ->expectsOutputToContain('Staged "Synthetic list"')
        ->expectsOutputToContain('skipped: page 2, NUMERACY/MATHEMATICS #4 (blank)')
        ->assertSuccessful();

    $edition = ReferenceEdition::query()->sole();
    expect($edition->only(['label', 'status', 'rows_total', 'rows_new', 'rows_skipped', 'rows_with_issues', 'source_url']))->toBe([
        'label' => 'Synthetic list', 'status' => 'draft', 'rows_total' => 12, 'rows_new' => 12, 'rows_skipped' => 1,
        'rows_with_issues' => 4, 'source_url' => 'https://example.test/list.pdf',
    ])
        ->and($edition->file_sha256)->toBe(hash_file('sha256', $path))
        ->and($edition->published_at->toDateString())->toBe('2024-12-01')
        ->and($edition->importRows()->orderBy('position')->pluck('title')->all())->toBe([
            'Sunrise Mathematics for Basic Schools',
            'Sunrise Mathematics for Basic Schools',
            'Lakeside Series Mathematics for Junior High Schools',
            'Counting Fun for Kindergarten',
            'Discover Science',
            'Discover Science',
            'Science Around Us',
            'Number Games Activity Book for Basic 2',
            'Twi Kasa Workbook 1',
            'Asante Twi Reader for Primary 3',
            'Fun with Fractions for Primary 2',
            'The Clever Tortoise',
        ])
        ->and($edition->importRows()->where('title', 'Science Around Us')->value('publisher_label'))->toBe('Kente Educational Press');
});

test('reference:import refuses without a label, a file or an owner', function () {
    User::query()->delete(); // the seeders create an owner
    $this->artisan('reference:import', ['path' => 'missing.pdf'])
        ->expectsOutputToContain('--edition is required')
        ->assertExitCode(2);

    $this->artisan('reference:import', ['path' => 'missing.pdf', '--edition' => 'X'])
        ->expectsOutputToContain('An owner account is needed')
        ->assertFailed();

    User::factory()->owner()->create();
    $this->artisan('reference:import', ['path' => storage_path('nope/missing.pdf'), '--edition' => 'X'])
        ->expectsOutputToContain('File not found')
        ->assertFailed();

    expect(ReferenceEdition::query()->count())->toBe(0);
});
