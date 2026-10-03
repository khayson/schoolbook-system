<?php

namespace App\Support;

use RuntimeException;

/**
 * Refuses to boot production when backups leave the server unencrypted: a bucket of
 * plain customer and money data is a breach waiting to happen.
 */
final class BackupGuard
{
    /**
     * @param  list<string>  $disks
     */
    public static function check(string $environment, array $disks, mixed $archivePassword): void
    {
        if ($environment !== 'production' || ! in_array('offsite', $disks, true)) {
            return;
        }

        if (is_string($archivePassword) && trim($archivePassword) !== '') {
            return;
        }

        throw new RuntimeException(
            'BACKUP_DISKS includes "offsite" but BACKUP_ARCHIVE_PASSWORD is empty. '
            .'Set an archive password (and keep a copy in the password manager) before backups leave this server. '
            .'See docs/operations.md.'
        );
    }
}
