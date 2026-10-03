<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/**
 * Backup problems are critical: until mail is configured, the log is the alarm.
 * Registered by event discovery (one handler per event type).
 */
class LogBackupProblems
{
    public function handle(BackupHasFailed|CleanupHasFailed|UnhealthyBackupWasFound $event): void
    {
        match (true) {
            $event instanceof BackupHasFailed => Log::critical('Backup failed', [
                'disk' => $event->diskName,
                'backup' => $event->backupName,
                'error' => $event->exception->getMessage(),
            ]),
            $event instanceof CleanupHasFailed => Log::critical('Backup cleanup failed', [
                'disk' => $event->diskName,
                'backup' => $event->backupName,
                'error' => $event->exception->getMessage(),
            ]),
            $event instanceof UnhealthyBackupWasFound => Log::critical('Unhealthy backup found', [
                'disk' => $event->diskName,
                'backup' => $event->backupName,
                'failures' => $event->failureMessages->pluck('message')->all(),
            ]),
        };
    }
}
