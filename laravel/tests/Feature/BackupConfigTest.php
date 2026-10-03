<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

function scheduledEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, $command));
}

test('backups are database only, on the local backups disk by default', function () {
    expect(config('backup.backup.source.files.include'))->toBe([])
        ->and(config('backup.backup.source.databases'))->toBe(['mysql'])
        ->and(config('backup.backup.database_dump_compressor'))->toBeNull()
        ->and(config('backup.backup.destination.compression_method'))->toBe(ZipArchive::CM_DEFAULT)
        ->and(config('backup.backup.destination.disks'))->toBe(['backups'])
        ->and(config('backup.monitor_backups.0.disks'))->toBe(['backups'])
        ->and(config('filesystems.disks.backups.driver'))->toBe('local')
        ->and(config('filesystems.disks.backups.root'))->toBe(storage_path('app/backups'));
});

test('the nightly backup runs at 01:30, followed by cleanup and monitor', function () {
    expect(scheduledEvent('backup:run --only-db')?->expression)->toBe('30 1 * * *')
        ->and(scheduledEvent('backup:clean')?->expression)->toBe('50 1 * * *')
        ->and(scheduledEvent('backup:monitor')?->expression)->toBe('0 2 * * *');
});

test('a scheduled backup that exits with failure is logged as critical', function () {
    Log::spy();
    $event = scheduledEvent('backup:run --only-db');
    $event->finish(app(), 1);

    Log::shouldHaveReceived('critical')->with('Scheduled backup:run exited with failure')->once();
});

test('backup failure, cleanup failure and unhealthy backups each log one critical entry', function () {
    Log::spy();

    event(new BackupHasFailed(new Exception('mysqldump: not found'), 'backups', 'schoolbook'));
    event(new CleanupHasFailed(new Exception('disk full'), 'backups', 'schoolbook'));
    event(new UnhealthyBackupWasFound('backups', 'schoolbook', new Collection([
        ['check' => 'MaximumAgeInDays', 'message' => 'The latest backup is too old'],
    ])));

    Log::shouldHaveReceived('critical')->with('Backup failed', Mockery::on(
        fn (array $c) => $c['error'] === 'mysqldump: not found' && $c['disk'] === 'backups'
    ))->once();
    Log::shouldHaveReceived('critical')->with('Backup cleanup failed', Mockery::on(
        fn (array $c) => $c['error'] === 'disk full'
    ))->once();
    Log::shouldHaveReceived('critical')->with('Unhealthy backup found', Mockery::on(
        fn (array $c) => $c['failures'] === ['The latest backup is too old']
    ))->once();
});
