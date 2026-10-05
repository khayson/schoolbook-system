<?php

use App\Console\DestructiveDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

/*
 * No real database is ever at risk here: "probe_dev" and "probe_test" are SQLite files
 * under storage/framework/testing, named like the dev and test databases. If the guard
 * ever failed open, the worst case is an emptied scratch file.
 */

function startCommand(string $command, array $options = []): void
{
    $input = new ArrayInput($options, new InputDefinition([
        new InputOption('database', null, InputOption::VALUE_OPTIONAL),
        new InputOption('force', null, InputOption::VALUE_NONE),
    ]));
    event(new CommandStarting($command, $input, new NullOutput));
}

beforeEach(function () {
    $dir = storage_path('framework/testing');
    is_dir($dir) || mkdir($dir, 0777, true);
    foreach (['schoolbook', 'schoolbook_test'] as $name) {
        touch("{$dir}/{$name}");
    }
    config([
        'database.connections.probe_dev' => ['driver' => 'sqlite', 'database' => "{$dir}/schoolbook", 'prefix' => ''],
        'database.connections.probe_test' => ['driver' => 'sqlite', 'database' => "{$dir}/schoolbook_test", 'prefix' => ''],
        'database.default' => 'probe_dev',
    ]);
    app()->detectEnvironment(fn () => 'local');
    putenv('ALLOW_DESTRUCTIVE_DB');
});

afterEach(function () {
    putenv('ALLOW_DESTRUCTIVE_DB');
    app()->detectEnvironment(fn () => 'testing');
});

test('commands that drop every table are refused on the default (dev) database', function (string $command) {
    expect(fn () => startCommand($command, ['--force' => true]))
        ->toThrow(RuntimeException::class, "Refusing {$command}: it would drop every table in \"".storage_path('framework/testing/schoolbook').'" (connection "probe_dev").');
})->with(DestructiveDatabaseGuard::COMMANDS);

test('they are allowed against a database whose name ends in _test', function (string $command) {
    startCommand($command, ['--database' => 'probe_test']);

    config(['database.default' => 'probe_test']);
    startCommand($command);

    expect(true)->toBeTrue();
})->with(DestructiveDatabaseGuard::COMMANDS);

test('the refusal tells the operator what to do', function () {
    expect(fn () => startCommand('db:wipe'))
        ->toThrow(RuntimeException::class, 'pass --database=mysql_testing. To wipe this database on purpose, set ALLOW_DESTRUCTIVE_DB=1 for this one command.');
});

test('ALLOW_DESTRUCTIVE_DB=1 allows it on purpose; any other value does not', function () {
    putenv('ALLOW_DESTRUCTIVE_DB=yes');
    expect(fn () => startCommand('db:wipe'))->toThrow(RuntimeException::class);

    putenv('ALLOW_DESTRUCTIVE_DB=1');
    startCommand('db:wipe');
    expect(true)->toBeTrue();
});

test('the testing environment and ordinary commands are not affected', function () {
    startCommand('migrate', ['--force' => true]);
    startCommand('db:seed');

    app()->detectEnvironment(fn () => 'testing');
    startCommand('migrate:fresh');

    expect(true)->toBeTrue();
});

test('the guard stops a real Artisan run before anything is dropped', function () {
    $file = storage_path('framework/testing/schoolbook');
    $probe = new PDO('sqlite:'.$file);
    $probe->exec('CREATE TABLE IF NOT EXISTS keep_me (id INTEGER)');

    // Real CLI runs route Symfony's command events to CommandStarting; unit tests do not, so turn it on.
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    $kernel->setArtisan(null);

    expect(fn () => $kernel->call('db:wipe', ['--force' => true]))
        ->toThrow(RuntimeException::class, 'Refusing db:wipe');

    expect($probe->query("SELECT count(*) FROM sqlite_master WHERE name = 'keep_me'")->fetchColumn())->toBe(1);
});
