<?php

namespace App\Filament\Resources\ReferenceEditions\Pages;

use App\Filament\Resources\ReferenceEditions\ReferenceEditionResource;
use Filament\Resources\Pages\ListRecords;

class ListReferenceEditions extends ListRecords
{
    protected static string $resource = ReferenceEditionResource::class;

    public function getSubheading(): ?string
    {
        return 'Each new NaCCA list is read on the server (the reference:import command, see docs/reference-catalog.md) '
            .'and appears here waiting for review. Nothing goes live until you publish it.';
    }
}
