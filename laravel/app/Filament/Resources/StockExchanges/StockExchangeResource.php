<?php

namespace App\Filament\Resources\StockExchanges;

use App\Enums\StockExchangeStatus;
use App\Filament\Resources\StockExchanges\Pages\CreateStockExchange;
use App\Filament\Resources\StockExchanges\Pages\EditStockExchange;
use App\Filament\Resources\StockExchanges\Pages\ListStockExchanges;
use App\Filament\Resources\StockExchanges\Pages\ViewStockExchange;
use App\Filament\Resources\StockExchanges\Schemas\StockExchangeForm;
use App\Filament\Resources\StockExchanges\Schemas\StockExchangeInfolist;
use App\Filament\Resources\StockExchanges\Tables\StockExchangesTable;
use App\Models\StockExchange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockExchangeResource extends Resource
{
    protected static ?string $model = StockExchange::class;

    protected static ?string $navigationLabel = 'Book exchanges';

    protected static ?string $modelLabel = 'book exchange';

    protected static ?string $pluralModelLabel = 'book exchanges';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function form(Schema $schema): Schema
    {
        return StockExchangeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockExchangeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockExchangesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'createdBy', 'items.product', 'sale', 'goodsReceipt']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockExchanges::route('/'),
            'create' => CreateStockExchange::route('/create'),
            'view' => ViewStockExchange::route('/{record}'),
            'edit' => EditStockExchange::route('/{record}/edit'),
        ];
    }

    public static function canEdit($record): bool
    {
        return $record->status === StockExchangeStatus::Draft;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
