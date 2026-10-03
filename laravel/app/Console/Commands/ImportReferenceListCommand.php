<?php

namespace App\Console\Commands;

use App\Actions\Reference\StageReferenceImport;
use App\Exceptions\ApiDomainException;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Stages an approved list for review in the admin (Approved list > Imports). Nothing
 * goes live until the owner publishes it there. Keep the PDF in storage/app/reference
 * (git-ignored): the list is NaCCA's copyright and must not be committed.
 */
class ImportReferenceListCommand extends Command
{
    protected $signature = 'reference:import
                            {path : The NaCCA approved list (PDF)}
                            {--edition= : Label, e.g. "NaCCA December 2024"}
                            {--source-url= : Where the file was downloaded from}
                            {--published= : Date printed on the list (YYYY-MM-DD)}
                            {--user= : Owner email recorded as importer (default: first owner)}';

    protected $description = 'Read a NaCCA approved list into a draft for review';

    public function handle(StageReferenceImport $stage): int
    {
        $label = trim((string) $this->option('edition'));
        if ($label === '') {
            $this->error('--edition is required, e.g. --edition="NaCCA December 2024".');

            return self::INVALID;
        }

        $user = $this->option('user')
            ? User::query()->where('email', $this->option('user'))->first()
            : User::query()->where('role', 'owner')->orderBy('id')->first();
        if ($user === null || ! $user->isOwner()) {
            $this->error('An owner account is needed (owner:create, or --user=<owner email>).');

            return self::FAILURE;
        }

        // Reading a 50-page PDF's positioned text needs more than PHP's default 128M.
        if ($this->memoryLimitBytes() < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }

        try {
            $edition = $stage->execute($user, (string) $this->argument('path'), $label, [
                'source_url' => $this->option('source-url') ?: null,
                'published_at' => $this->option('published') ?: null,
            ]);
        } catch (ApiDomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Staged \"{$edition->label}\" (#{$edition->id}) for review.");
        $this->table(['Rows', 'New', 'Unchanged', 'Changed', 'Removed', 'With issues', 'Skipped'], [[
            $edition->rows_total, $edition->rows_new, $edition->rows_unchanged, $edition->rows_changed,
            $edition->rows_removed, $edition->rows_with_issues, $edition->rows_skipped,
        ]]);
        foreach ($edition->skipped ?? [] as $skip) {
            $this->line("  skipped: page {$skip['page']}, {$skip['section']} #{$skip['serial']} ({$skip['reason']})");
        }
        $this->line('Review and publish it in the admin: Approved list > Imports.');

        return self::SUCCESS;
    }

    private function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
