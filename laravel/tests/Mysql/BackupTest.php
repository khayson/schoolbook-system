<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Runs the real backup:run (mysqldump) with the production backup config, its "mysql"
 * connection pointed at schoolbook_test. Needs mysqldump on PATH or DB_DUMP_BINARY_PATH.
 *
 * The package config is deliberately not overridden here: the command receives it when
 * Artisan boots (before these hooks), so overrides would be silently ignored. The dumper
 * reads the connection's config at run time, so redirecting the connection does work.
 */

beforeEach(function () {
    Storage::fake('backups');
    config(['database.connections.mysql' => [
        ...config('database.connections.mysql_testing'),
        'dump' => config('database.connections.mysql.dump'),
    ]]);
});

test('backup:run --only-db dumps the test database into a zip on the backups disk', function () {
    Customer::factory()->create(['name' => 'Backup Probe School']);

    $this->artisan('backup:run', ['--only-db' => true, '--disable-notifications' => true])
        ->assertSuccessful();

    $files = collect(Storage::disk('backups')->allFiles())->filter(fn (string $f) => str_ends_with($f, '.zip'));
    expect($files)->toHaveCount(1);
    expect($files->first())->toStartWith(config('backup.backup.name').'/');

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('backups')->path($files->first())))->toBeTrue();

    $entries = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i));
    $dumpEntry = $entries->first(fn (string $name) => str_ends_with($name, '.sql'));
    expect($dumpEntry)->not->toBeNull('entries: '.$entries->implode(', '));

    expect($dumpEntry)->toEndWith('mysql-schoolbook_test.sql');
    $sql = $zip->getFromName($dumpEntry);
    // Deflated inside the archive, not stored raw.
    expect($zip->statName($dumpEntry)['comp_method'])->toBe(ZipArchive::CM_DEFLATE);
    $zip->close();

    // A restorable dump: schema plus the data written above.
    expect($sql)->toContain('CREATE TABLE `customers`')
        ->and($sql)->toContain('CREATE TABLE `stock_movements`')
        ->and($sql)->toContain('Backup Probe School');
});

test('a failing backup is logged as critical', function () {
    config(['database.connections.mysql.dump.dump_binary_path' => 'C:/definitely-not-here']);
    Log::spy();

    $this->artisan('backup:run', ['--only-db' => true])->assertFailed();

    // Failed in the dump step (the binary), not on some other misconfiguration.
    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message, array $context = []) => $message === 'Backup failed'
            && str_contains($context['error'] ?? '', 'The dump process failed'))
        ->once();
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});
