<?php

use App\Providers\AppServiceProvider;
use App\Support\BackupGuard;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

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

test('one monitored backup only, with retries, verification and R2-safe storage caps', function () {
    expect(config('backup.monitor_backups'))->toHaveCount(1)
        ->and(config('backup.monitor_backups.0.name'))->toBe('schoolbook')
        ->and(config('backup.monitor_backups.0.health_checks'))->toBe([
            MaximumAgeInDays::class => 1,
            MaximumStorageInMegabytes::class => 2000,
        ])
        ->and(config('backup.cleanup.default_strategy.delete_oldest_backups_when_using_more_megabytes_than'))->toBe(2000)
        ->and(config('backup.backup.verify_backup'))->toBeTrue()
        ->and(config('backup.backup.tries'))->toBe(3)
        ->and(config('backup.backup.retry_delay'))->toBe(60);
});

test('backup:monitor fails and logs critical when no backup exists', function () {
    Storage::fake('backups');
    Log::spy();

    $this->artisan('backup:monitor')
        ->expectsOutputToContain('considered unhealthy')
        ->assertFailed();

    Log::shouldHaveReceived('critical')->with('Unhealthy backup found', Mockery::on(
        fn (array $c) => $c['disk'] === 'backups' && $c['backup'] === 'schoolbook' && $c['failures'] !== []
    ))->once();
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

/**
 * Re-evaluates routes/console.php against a fresh schedule (the file reads config once).
 */
function reloadSchedule(): void
{
    app()->instance(Schedule::class, new Schedule(config('app.timezone')));
    Facade::clearResolvedInstance(Schedule::class);
    require base_path('routes/console.php');
}

/**
 * @return array<int, array{request: RequestInterface}>
 */
function fakePingClient(): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(200))));
    $stack->push(Middleware::history($history));
    app()->instance(ClientInterface::class, new Client(['handler' => $stack]));

    return $history;
}

test('with BACKUP_HEARTBEAT_URL set, a successful backup pings it and a failed one does not', function () {
    config(['services.heartbeat.backup_url' => 'https://hc-ping.example/backup-uuid']);
    reloadSchedule();
    $history = fakePingClient();
    $event = scheduledEvent('backup:run --only-db');

    $event->finish(app(), 1);
    expect($history)->toHaveCount(0);

    $event->finish(app(), 0);
    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('GET')
        ->and((string) $history[0]['request']->getUri())->toBe('https://hc-ping.example/backup-uuid');
});

test('without a heartbeat URL nothing is pinged', function () {
    config(['services.heartbeat.backup_url' => null]);
    reloadSchedule();
    $history = fakePingClient();

    scheduledEvent('backup:run --only-db')->finish(app(), 0);

    expect($history)->toHaveCount(0);
});

test('production refuses to boot with the offsite disk and no archive password', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'backup.backup.destination.disks' => ['backups', 'offsite'],
        'backup.backup.password' => '',
    ]);

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'BACKUP_DISKS includes "offsite" but BACKUP_ARCHIVE_PASSWORD is empty');

    config(['backup.backup.password' => 'correct horse battery staple']);
    (new AppServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

test('the archive password guard only applies to production with the offsite disk', function (string $env, array $disks, ?string $password, bool $throws) {
    $check = fn () => BackupGuard::check($env, $disks, $password);

    $throws ? expect($check)->toThrow(RuntimeException::class) : expect($check())->toBeNull();
})->with([
    'production, offsite, no password' => ['production', ['backups', 'offsite'], null, true],
    'production, offsite, blank password' => ['production', ['offsite'], '   ', true],
    'production, offsite, password' => ['production', ['backups', 'offsite'], 's3cret', false],
    'production, local only' => ['production', ['backups'], null, false],
    'local, offsite, no password' => ['local', ['backups', 'offsite'], null, false],
]);
