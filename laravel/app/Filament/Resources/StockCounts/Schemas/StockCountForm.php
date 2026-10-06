<?php

namespace App\Filament\Resources\StockCounts\Schemas;

use App\Models\Language;
use App\Models\Level;
use App\Models\Publisher;
use App\Models\Subject;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockCountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Which products')
                ->description('Leave all empty to count every active product.')
                ->schema([
                    Select::make('level_id')->label('Level')->options(fn () => Level::query()->orderBy('sort_order')->pluck('name', 'id')),
                    Select::make('subject_id')->label('Subject')->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('language_id')->label('Language')->options(fn () => Language::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('publisher_id')->label('Publisher')->options(fn () => Publisher::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                ])
                ->columns(4)
                ->columnSpanFull(),
            Textarea::make('notes')->maxLength(1000)->columnSpanFull(),
        ]);
    }
}
