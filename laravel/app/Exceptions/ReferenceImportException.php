<?php

namespace App\Exceptions;

class ReferenceImportException extends ApiDomainException
{
    public static function draftExists(int $editionId): self
    {
        return new self(
            'Another imported list is still waiting for review. Publish or discard it first.',
            'reference_draft_exists',
            409,
            ['edition_id' => $editionId],
        );
    }

    public static function alreadyImported(int $editionId): self
    {
        return new self('This exact file is the live list already.', 'reference_already_imported', 409, ['edition_id' => $editionId]);
    }

    public static function notDraft(): self
    {
        return new self('This list is no longer waiting for review.', 'reference_not_draft', 409);
    }

    public static function fileNotFound(string $path): self
    {
        return new self("File not found: {$path}", 'reference_file_not_found', 422);
    }

    public static function nothingParsed(): self
    {
        return new self('No numbered rows were found. Is this the NaCCA approved list?', 'reference_nothing_parsed', 422);
    }

    public static function rowHasErrors(): self
    {
        return new self('This row still has errors. Fix it or exclude it.', 'reference_row_has_errors', 422);
    }

    public static function removedRowNotEditable(): self
    {
        return new self('A removed title cannot be edited: accept (withdraw it) or exclude (keep it).', 'reference_row_not_editable', 422);
    }

    public static function nothingToPublish(): self
    {
        return new self('No rows have been accepted yet.', 'reference_nothing_to_publish', 422);
    }
}
