<?php

namespace App\Filament\Resources\ReferenceBooks\Pages;

use App\Filament\Resources\ReferenceBooks\ReferenceBookResource;
use App\Models\ReferenceEdition;
use Filament\Resources\Pages\ListRecords;

class ListReferenceBooks extends ListRecords
{
    protected static string $resource = ReferenceBookResource::class;

    public function getSubheading(): ?string
    {
        $active = ReferenceEdition::active();

        return $active === null
            ? 'No approved list has been published yet (Approved list > Imports).'
            : "Live list: {$active->label}. NaCCA's list is for in-shop lookup only; do not reproduce it.";
    }
}
