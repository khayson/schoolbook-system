<?php

use App\Console\Commands\PruneIdempotencyKeysCommand;
use App\Console\Commands\ReconcileCustomersCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneIdempotencyKeysCommand::class)->daily();

// Report-only; a non-zero exit flags drift in the cached money balances.
Schedule::command(ReconcileCustomersCommand::class)->dailyAt('02:30');
