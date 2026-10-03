<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staging: one parsed row of a draft edition, or a live title missing from it
 * (action "removed"). Nothing here is live until the edition is published.
 *
 * issues: list of {code, severity: error|warning, message, data?}. Rows with an error
 * cannot be accepted; they must be fixed or excluded.
 */
class ReferenceImportRow extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'issues' => 'array',
            'changes' => 'array',
            'resolved' => 'boolean',
            'excluded' => 'boolean',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(ReferenceEdition::class, 'reference_edition_id');
    }

    public function referenceBook(): BelongsTo
    {
        return $this->belongsTo(ReferenceBook::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function hasErrors(): bool
    {
        return collect($this->issues ?? [])->contains(fn (array $issue) => $issue['severity'] === 'error');
    }

    public function hasIssues(): bool
    {
        return ($this->issues ?? []) !== [];
    }

    /**
     * @return list<array{code: string, severity: string, message: string, data?: array}>
     */
    public function issuesWithCode(string $code): array
    {
        return array_values(array_filter($this->issues ?? [], fn (array $issue) => $issue['code'] === $code));
    }
}
