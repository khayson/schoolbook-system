<?php

namespace App\Filament\Resources\ReferenceEditions;

use App\Filament\Resources\ReferenceEditions\Pages\ListReferenceEditions;
use App\Filament\Resources\ReferenceEditions\Pages\ReviewReferenceEdition;
use App\Filament\Resources\ReferenceEditions\Tables\ReferenceEditionsTable;
use App\Models\ReferenceEdition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReferenceEditionResource extends Resource
{
    protected static ?string $model = ReferenceEdition::class;

    protected static ?string $navigationLabel = 'Imports';

    protected static ?string $modelLabel = 'approved list import';

    protected static string|\UnitEnum|null $navigationGroup = 'Approved list';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    public static function table(Table $table): Table
    {
        return ReferenceEditionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferenceEditions::route('/'),
            'review' => ReviewReferenceEdition::route('/{record}/review'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
