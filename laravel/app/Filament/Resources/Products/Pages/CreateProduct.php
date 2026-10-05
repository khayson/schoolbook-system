<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Catalog\CreateProduct as CreateProductAction;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = ProductResource::class;

    /** Through the Action: generated SKU when blank, same rules as the API. */
    protected function handleRecordCreation(array $data): Model
    {
        return DomainErrorNotifier::attempt(fn () => app(CreateProductAction::class)->execute($this->getUser(), $data));
    }
}
