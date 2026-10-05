<?php

namespace App\Console;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Refuses commands that drop every table unless the target is clearly a throwaway
 * database. Added after a migrate:fresh meant for schoolbook_test wiped the dev
 * database (docs/decisions.md, 2026-10-03): a written rule did not stop it, this does.
 *
 * Allowed when (a) the app environment is "testing", (b) the target database name ends
 * in "_test", or (c) ALLOW_DESTRUCTIVE_DB=1 is set in the shell for that one command.
 * The override must never live in .env (it would silently disable the guard for good):
 * if .env mentions it at all, these commands are refused until the line is removed.
 */
class DestructiveDatabaseGuard
{
    public const COMMANDS = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];

    public function handle(CommandStarting $event): void
    {
        $envFile = base_path('.env');

        $this->check(
            (string) $event->command,
            $event->input->getParameterOption('--database', null, true) ?: null,
            app()->environment(),
            getenv('ALLOW_DESTRUCTIVE_DB') ?: ($_SERVER['ALLOW_DESTRUCTIVE_DB'] ?? null),
            is_file($envFile) ? (string) file_get_contents($envFile) : '',
        );
    }

    public function check(string $command, ?string $connection, string $environment, mixed $override, string $envFile = ''): void
    {
        if (! in_array($command, self::COMMANDS, true) || $environment === 'testing') {
            return;
        }

        if (preg_match('/^\s*(export\s+)?ALLOW_DESTRUCTIVE_DB\s*=/m', $envFile)) {
            throw new RuntimeException(
                "Refusing {$command}: ALLOW_DESTRUCTIVE_DB is set in .env, which would switch this guard off for every command. "
                .'Remove that line. Set the variable in the shell for one command only when you really mean to wipe a database.'
            );
        }

        if ((string) $override === '1') {
            return;
        }

        $connection ??= (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if (str_ends_with($database, '_test')) {
            return;
        }

        throw new RuntimeException(
            "Refusing {$command}: it would drop every table in \"{$database}\" (connection \"{$connection}\"). "
            .'For the throwaway test database pass --database=mysql_testing. '
            .'To wipe this database on purpose, set ALLOW_DESTRUCTIVE_DB=1 for this one command.'
        );
    }
}
