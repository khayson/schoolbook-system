<?php

use App\Models\NumberSequence;
use App\Services\NumberSequenceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('sequences:prepare creates next year\'s inv, rct, grn and cnt rows at zero', function () {
    Carbon::setTestNow('2026-12-15 01:00:00');

    $this->artisan('sequences:prepare')
        ->expectsOutputToContain('Prepared inv, rct, grn, cnt sequences for 2027.')
        ->assertSuccessful();

    expect(NumberSequence::query()->where('year', 2027)->orderBy('key')->pluck('last_number', 'key')->all())
        ->toBe(['cnt' => 0, 'grn' => 0, 'inv' => 0, 'rct' => 0]);

    // The first allocation of the new year uses the prepared row and starts at 1.
    expect(app(NumberSequenceService::class)->next('inv', 2027))->toBe(1);
});

test('sequences:prepare is idempotent and never resets a counter', function () {
    app(NumberSequenceService::class)->next('rct', 2030);
    app(NumberSequenceService::class)->next('rct', 2030);

    $this->artisan('sequences:prepare', ['--year' => 2030])->assertSuccessful();
    $this->artisan('sequences:prepare', ['--year' => 2030])->assertSuccessful();

    expect(NumberSequence::query()->where('key', 'rct')->where('year', 2030)->value('last_number'))->toBe(2)
        ->and(NumberSequence::query()->where('year', 2030)->count())->toBe(4);
});

test('sequences:prepare rejects a nonsense year', function () {
    $this->artisan('sequences:prepare', ['--year' => 12])->assertFailed();

    expect(NumberSequence::query()->count())->toBe(0);
});

test('sequences:prepare is scheduled for 15 December', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'sequences:prepare'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1 15 12 *');
});
