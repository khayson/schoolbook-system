<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Filament\Support\GhsInput;
use App\Services\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(collect(CustomerType::cases())->mapWithKeys(
                        fn (CustomerType $type) => [$type->value => ucfirst($type->value)],
                    )->all())
                    ->required(),
                Select::make('region')
                    ->options(collect(GhanaRegion::cases())->mapWithKeys(
                        fn (GhanaRegion $region) => [$region->value => $region->value],
                    )->all())
                    ->searchable()
                    ->required(),
                TextInput::make('district')->maxLength(255),
                Textarea::make('address')->columnSpanFull(),
                TextInput::make('contact_person')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(255),
                TextInput::make('email')->email()->maxLength(255),
                GhsInput::make('credit_limit')
                    ->label('Credit limit')
                    ->helperText('Leave empty for no limit. Exceeding it is a warning on confirm, not a block.'),
                Placeholder::make('outstanding_balance_display')
                    ->label('Owes')
                    ->content(fn ($record): string => Money::formatGhsGrouped($record?->outstanding_balance ?? 0))
                    ->visibleOn('edit'),
                Placeholder::make('credit_balance_display')
                    ->label('Credit')
                    ->content(fn ($record): string => Money::formatGhsGrouped($record?->credit_balance ?? 0))
                    ->visibleOn('edit'),
                Textarea::make('notes')->columnSpanFull(),
                Toggle::make('is_active')->default(true)->required(),
            ]);
    }
}
