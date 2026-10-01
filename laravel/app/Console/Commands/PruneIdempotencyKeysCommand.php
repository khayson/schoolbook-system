<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune
                            {--hours=72 : Delete keys older than this many hours}';

    protected $description = 'Delete idempotency keys older than the retention window (default 72 hours)';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $deleted = IdempotencyKey::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} idempotency key(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
