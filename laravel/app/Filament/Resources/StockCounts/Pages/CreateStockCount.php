<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Actions\Inventory\CreateStockCount as CreateStockCountAction;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockCount extends CreateRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = StockCountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $filters = array_filter(
            array_intersect_key($data, array_flip(CreateStockCountAction::FILTERS)),
            fn ($v) => $v !== null && $v !== '',
        );

        return DomainErrorNotifier::attempt(fn () => app(CreateStockCountAction::class)->execute($this->getUser(), [
            'filters' => array_map('intval', $filters),
            'notes' => $data['notes'] ?? null,
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
