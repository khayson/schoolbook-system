<?php

namespace App\Filament\Resources\StockExchanges\Pages;

use App\Filament\Resources\StockExchanges\StockExchangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockExchanges extends ListRecords
{
    protected static string $resource = StockExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New exchange'),
        ];
    }
}
