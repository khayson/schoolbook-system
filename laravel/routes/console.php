<?php

use App\Console\Commands\PrepareSequencesCommand;
use App\Console\Commands\PruneIdempotencyKeysCommand;
use App\Console\Commands\ReconcileCustomersCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneIdempotencyKeysCommand::class)->daily();

// Report-only; a non-zero exit flags drift in the cached money balances.
Schedule::command(ReconcileCustomersCommand::class)
    ->dailyAt('02:30')
    ->onFailure(fn () => ReconcileCustomersCommand::logScheduledFailure());

// Nightly database-only backup, then retention cleanup and a health check. Failures inside
// the package raise events logged as critical (App\Listeners\LogBackupProblems); the
// onFailure hooks also catch a command that dies before it can raise one. The heartbeat
// ping catches what no log can: the scheduler not running at all.
$heartbeat = config('services.heartbeat.backup_url');
Schedule::command('backup:run --only-db')
    ->dailyAt('01:30')
    ->onFailure(fn () => Log::critical('Scheduled backup:run exited with failure'))
    ->pingOnSuccessIf(filled($heartbeat), (string) $heartbeat);
Schedule::command('backup:clean')
    ->dailyAt('01:50')
    ->onFailure(fn () => Log::critical('Scheduled backup:clean exited with failure'));
Schedule::command('backup:monitor')
    ->dailyAt('02:00')
    ->onFailure(fn () => Log::critical('Scheduled backup:monitor exited with failure'));

// Next year's inv/rct/grn rows exist before the first request of the year needs them.
Schedule::command(PrepareSequencesCommand::class)->yearlyOn(12, 15, '01:00');
