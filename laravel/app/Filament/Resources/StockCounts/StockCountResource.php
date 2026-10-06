<?php

namespace App\Filament\Resources\StockCounts;

use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Resources\StockCounts\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StockCounts\Schemas\StockCountForm;
use App\Filament\Resources\StockCounts\Schemas\StockCountInfolist;
use App\Filament\Resources\StockCounts\Tables\StockCountsTable;
use App\Models\StockCount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Stock-take (spec 6.3; docs/acceptance-phase3.md 3.10). Counts are entered in the items
 * table and applied once from the view page; all changes go through the StockCount actions.
 */
class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $navigationLabel = 'Stock-takes';

    protected static ?string $modelLabel = 'stock-take';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return StockCountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockCountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockCountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCounts::route('/'),
            'create' => CreateStockCount::route('/create'),
            'view' => ViewStockCount::route('/{record}'),
        ];
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'open' => 'warning',
            'applied' => 'success',
            default => 'gray',
        };
    }
}
