<?php

namespace App\Filament\Resources\PriceRules\Schemas;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Enums\PriceRuleCustomerScope;
use App\Enums\PriceRuleFloorPolicy;
use App\Enums\PriceRuleScopeType;
use App\Enums\PriceRuleType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PriceRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('type')
                    ->options(collect(PriceRuleType::cases())->mapWithKeys(
                        fn (PriceRuleType $t) => [$t->value => str_replace('_', ' ', $t->value)],
                    )->all())
                    ->required()
                    ->live(),
                TextInput::make('value')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Basis points for percent (1000 = 10%); pesewas for amounts/fixed price.'),
                Select::make('scope_type')
                    ->options(collect(PriceRuleScopeType::cases())->mapWithKeys(
                        fn (PriceRuleScopeType $t) => [$t->value => str_replace('_', ' ', $t->value)],
                    )->all())
                    ->required()
                    ->live(),
                TextInput::make('scope_id')
                    ->numeric()
                    ->visible(fn (Get $get): bool => ($get('scope_type') ?? 'all') !== 'all')
                    ->required(fn (Get $get): bool => ($get('scope_type') ?? 'all') !== 'all')
                    ->helperText('Product / publisher / subject / language / level / level_group id.'),
                Select::make('customer_scope')
                    ->options(collect(PriceRuleCustomerScope::cases())->mapWithKeys(
                        fn (PriceRuleCustomerScope $t) => [$t->value => str_replace('_', ' ', $t->value)],
                    )->all())
                    ->required()
                    ->live(),
                TextInput::make('customer_scope_value')
                    ->visible(fn (Get $get): bool => ($get('customer_scope') ?? 'all') !== 'all')
                    ->required(fn (Get $get): bool => ($get('customer_scope') ?? 'all') !== 'all')
                    ->helperText(fn (Get $get): string => match ($get('customer_scope')) {
                        'customer' => 'Customer id.',
                        'customer_type' => 'One of: '.implode(', ', array_column(CustomerType::cases(), 'value')),
                        'region' => 'One of: '.implode(', ', array_column(GhanaRegion::cases(), 'value')),
                        default => '',
                    }),
                TextInput::make('min_quantity')->numeric()->minValue(1),
                TextInput::make('max_quantity')->numeric()->minValue(1),
                TextInput::make('min_order_total')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Pesewas; for order-level rules.'),
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
                TextInput::make('priority')->numeric()->default(0)->required(),
                Toggle::make('stackable')->default(false),
                Select::make('floor_policy')
                    ->options(collect(PriceRuleFloorPolicy::cases())->mapWithKeys(
                        fn (PriceRuleFloorPolicy $t) => [$t->value => str_replace('_', ' ', $t->value)],
                    )->all())
                    ->default(PriceRuleFloorPolicy::Warn->value)
                    ->required(),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
