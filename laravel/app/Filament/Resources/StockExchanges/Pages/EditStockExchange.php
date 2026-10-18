<?php

namespace App\Filament\Resources\StockExchanges\Pages;

use App\Actions\Inventory\UpdateDraftStockExchange;
use App\Enums\StockExchangeStatus;
use App\Filament\Resources\StockExchanges\Schemas\StockExchangeForm;
use App\Filament\Resources\StockExchanges\StockExchangeResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\StockExchange;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStockExchange extends EditRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = StockExchangeResource::class;

    protected static ?string $title = 'Edit book exchange';

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StockExchange $record */
        return DomainErrorNotifier::attempt(fn () => app(UpdateDraftStockExchange::class)->execute($this->getUser(), $record, [
            'customer_id' => (int) $data['customer_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'counterparty_name' => $data['counterparty_name'],
            'settlement' => $data['settlement'] ?? null,
            'notes' => $data['notes'] ?? null,
            'items' => StockExchangeForm::linesFromState($data['items'] ?? []),
        ]));
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var StockExchange $record */
        $record = $this->getRecord();
        $data['items'] = $record->items->map(fn ($item): array => [
            'direction' => $item->direction->value,
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'unit_price' => $item->unit_price,
        ])->all();
        $data['settlement'] = $record->settlement?->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;
        if ($record instanceof StockExchange) {
            return $record->status === StockExchangeStatus::Draft;
        }

        return parent::canAccess($parameters);
    }
}
