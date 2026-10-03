<?php

namespace App\Actions\Reference;

use App\Exceptions\ReferenceImportException;
use App\Models\ReferenceEdition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Throws away a draft: its staging rows are deleted, the edition record stays (status
 * "discarded") so the import history is complete. The live list is untouched.
 */
class DiscardReferenceEdition
{
    public function execute(User $user, ReferenceEdition $edition): ReferenceEdition
    {
        return DB::transaction(function () use ($user, $edition): ReferenceEdition {
            $edition = ReferenceEdition::query()->lockForUpdate()->findOrFail($edition->id);
            if (! $edition->isDraft()) {
                throw ReferenceImportException::notDraft();
            }
            $edition->importRows()->delete();
            $edition->forceFill(['status' => ReferenceEdition::STATUS_DISCARDED])->save();

            activity('reference')->causedBy($user)->performedOn($edition)->log('reference list discarded');

            return $edition;
        });
    }
}
