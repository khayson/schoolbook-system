<?php

namespace App\Filament\Resources\ReferenceEditions\Pages;

use App\Filament\Resources\ReferenceEditions\ReferenceEditionResource;
use Filament\Resources\Pages\ListRecords;

class ListReferenceEditions extends ListRecords
{
    protected static string $resource = ReferenceEditionResource::class;

    public function getSubheading(): ?string
    {
        return 'Import a new NaCCA list on the server with: php artisan reference:import <file.pdf> --edition="NaCCA <month year>". '
            .'It appears here as a draft to review; nothing goes live until you publish it.';
    }
}
