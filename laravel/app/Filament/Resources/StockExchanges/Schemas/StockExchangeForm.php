<?php

namespace App\Filament\Resources\StockExchanges\Schemas;

use App\Actions\Customers\CreateCustomer;
use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Enums\StockExchangeDirection;
use App\Enums\StockExchangeSettlement;
use App\Filament\Support\GhsInput;
use App\Models\Customer;
use App\Models\Product;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class StockExchangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('1. Who is exchanging')
                    ->description('School, reseller, or individual — pick someone on file, or save a new person here without leaving.')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Person / dealer (any type)')
                            ->helperText('Who may owe you for books they took. Not schools only.')
                            ->relationship(
                                name: 'customer',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (Customer $record): string => $record->name.' ('.ucfirst($record->type->value).')',
                            )
                            ->searchable(['name', 'code', 'phone'])
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                if (! $state) {
                                    return;
                                }
                                $name = Customer::query()->whereKey($state)->value('name');
                                if ($name && blank($get('counterparty_name'))) {
                                    $set('counterparty_name', $name);
                                }
                            })
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Full name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('type')
                                    ->label('Type')
                                    ->options([
                                        CustomerType::Individual->value => 'Individual',
                                        CustomerType::Reseller->value => 'Reseller / dealer',
                                        CustomerType::School->value => 'School',
                                    ])
                                    ->default(CustomerType::Individual->value)
                                    ->required(),
                                Select::make('region')
                                    ->options(collect(GhanaRegion::cases())->mapWithKeys(
                                        fn (GhanaRegion $region) => [$region->value => $region->value],
                                    )->all())
                                    ->searchable()
                                    ->required()
                                    ->default(GhanaRegion::GreaterAccra->value),
                                TextInput::make('phone')->tel()->maxLength(255),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $customer = app(CreateCustomer::class)->execute([
                                    'name' => $data['name'],
                                    'type' => $data['type'],
                                    'region' => $data['region'],
                                    'phone' => $data['phone'] ?? null,
                                    'is_active' => true,
                                ]);

                                return $customer->id;
                            })
                            ->createOptionModalHeading('Save new person'),
                        TextInput::make('counterparty_name')
                            ->label('Name on the paper / list')
                            ->helperText('Filled from the person above; edit if the list uses a different spelling.')
                            ->required()
                            ->maxLength(255),
                        Select::make('supplier_id')
                            ->label('Supplier (optional)')
                            ->helperText('Only if Taken books should link to a supplier.')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),

                Section::make('2. Books each side took')
                    ->description('For every title: who took it, how many copies, and the price.')
                    ->schema([
                        Repeater::make('items')
                            ->label('Book lines')
                            ->schema([
                                Select::make('direction')
                                    ->label('Who took these?')
                                    ->options([
                                        StockExchangeDirection::In->value => 'They gave us (Taken)',
                                        StockExchangeDirection::Out->value => 'They took from us',
                                    ])
                                    ->required()
                                    ->live(),
                                Select::make('product_id')
                                    ->label('Book')
                                    ->options(fn (): array => Product::query()
                                        ->where('is_active', true)
                                        ->orderBy('title')
                                        ->pluck('title', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required(),
                                TextInput::make('quantity')
                                    ->label('How many')
                                    ->numeric()
                                    ->minValue(1)
                                    ->required(),
                                GhsInput::make('unit_price')
                                    ->label('Selling price (they took)')
                                    ->helperText('Blank = normal price rules.')
                                    ->visible(fn ($get): bool => $get('direction') === StockExchangeDirection::Out->value),
                                GhsInput::make('unit_cost')
                                    ->label('Cost (Taken)')
                                    ->helperText('Blank = product cost price.')
                                    ->visible(fn ($get): bool => $get('direction') === StockExchangeDirection::In->value),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),

                Section::make('3. How they pay (for books they took)')
                    ->description('Cash and carry, installment, or pay later. After confirm, use the sale to take money.')
                    ->schema([
                        Radio::make('settlement')
                            ->label('Settlement')
                            ->options([
                                StockExchangeSettlement::Cash->value => StockExchangeSettlement::Cash->label(),
                                StockExchangeSettlement::Installment->value => StockExchangeSettlement::Installment->label(),
                                StockExchangeSettlement::PayLater->value => StockExchangeSettlement::PayLater->label(),
                            ])
                            ->descriptions([
                                StockExchangeSettlement::Cash->value => StockExchangeSettlement::Cash->description(),
                                StockExchangeSettlement::Installment->value => StockExchangeSettlement::Installment->description(),
                                StockExchangeSettlement::PayLater->value => StockExchangeSettlement::PayLater->description(),
                            ])
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @param  list<array{direction?: string, product_id?: mixed, quantity?: mixed, unit_cost?: mixed, unit_price?: mixed}>  $items
     * @return list<array{direction: string, product_id: int, quantity: int, unit_cost: int|null, unit_price: int|null}>
     */
    public static function linesFromState(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'direction' => (string) ($item['direction'] ?? ''),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_cost' => isset($item['unit_cost']) && $item['unit_cost'] !== null && $item['unit_cost'] !== ''
                    ? (int) $item['unit_cost']
                    : null,
                'unit_price' => isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== ''
                    ? (int) $item['unit_price']
                    : null,
            ])
            ->values()
            ->all();
    }
}
