<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Services\Money;
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
                TextInput::make('credit_limit')
                    ->label('Credit limit (GHS)')
                    ->numeric()
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? Money::pesewasToGhs($state) : null)
                    ->dehydrateStateUsing(fn ($state): ?int => $state === null || $state === ''
                        ? null
                        : Money::ghsToPesewas(is_string($state) || is_int($state) ? $state : (string) $state)),
                Textarea::make('notes')->columnSpanFull(),
                Toggle::make('is_active')->default(true)->required(),
            ]);
    }
}
