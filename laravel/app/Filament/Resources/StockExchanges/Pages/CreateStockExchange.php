<?php

namespace App\Filament\Resources\StockExchanges\Pages;

use App\Actions\Inventory\CreateStockExchange as CreateStockExchangeAction;
use App\Filament\Resources\StockExchanges\Schemas\StockExchangeForm;
use App\Filament\Resources\StockExchanges\StockExchangeResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockExchange extends CreateRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = StockExchangeResource::class;

    protected static ?string $title = 'New book exchange';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return DomainErrorNotifier::attempt(fn () => app(CreateStockExchangeAction::class)->execute($this->getUser(), [
            'customer_id' => (int) $data['customer_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'counterparty_name' => $data['counterparty_name'],
            'settlement' => $data['settlement'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => StockExchangeForm::linesFromState($data['items'] ?? []),
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
