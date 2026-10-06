<?php

namespace App\Filament\Resources\DirectorySchools;

use App\Actions\Schools\AddDirectorySchoolAsCustomer;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DirectorySchools\Pages\ListDirectorySchools;
use App\Filament\Support\DomainErrorNotifier;
use App\Models\Customer;
use App\Models\DirectorySchool;
use App\Services\Schools\DirectorySchoolSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * School directory (docs/school-directory.md): schools in Greater Accra and Central from
 * OpenStreetMap, searched to add one as a customer in one step. Read-only otherwise;
 * refreshed with `php artisan schools:import`.
 */
class DirectorySchoolResource extends Resource
{
    protected static ?string $model = DirectorySchool::class;

    protected static ?string $navigationLabel = 'School directory';

    protected static ?string $modelLabel = 'directory school';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->listed()->with('customer:id,code,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(DirectorySchool::ATTRIBUTION.' It lists the schools mapped there, not every school: add any other school as a customer directly.')
            ->defaultSort('search_name')
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => DirectorySchoolSearch::apply($query, $search))
            ->columns([
                TextColumn::make('name')->wrap(),
                TextColumn::make('district')->placeholder('-'),
                TextColumn::make('town')->placeholder('-')->toggleable(),
                TextColumn::make('levels')->label('Levels')->state(fn (DirectorySchool $record): ?string => $record->levelLabel())->placeholder('-'),
                TextColumn::make('ownership')->badge()->placeholder('-')->toggleable(),
                TextColumn::make('phone')->placeholder('-')->toggleable(),
                TextColumn::make('customer.code')
                    ->label('Customer')
                    ->placeholder('Not added')
                    ->url(fn (DirectorySchool $record): ?string => $record->customer_id === null ? null : CustomerResource::getUrl('edit', ['record' => $record->customer_id])),
            ])
            ->filters([
                SelectFilter::make('region')->options(['Greater Accra' => 'Greater Accra', 'Central' => 'Central']),
                SelectFilter::make('district')->options(fn () => DirectorySchool::query()->listed()->whereNotNull('district')
                    ->distinct()->orderBy('district')->pluck('district', 'district')->all())->searchable(),
                TernaryFilter::make('added')
                    ->label('Added as customer')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('customer_id'),
                        false: fn (Builder $q) => $q->whereNull('customer_id'),
                    ),
            ])
            ->recordActions([
                Action::make('addAsCustomer')
                    ->label('Add as customer')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (DirectorySchool $record): bool => $record->customer_id === null)
                    ->modalHeading(fn (DirectorySchool $record): string => "Add {$record->name}")
                    ->modalDescription(fn (DirectorySchool $record): string => trim("{$record->region}, ".($record->district ?? '').'. Name, region, district and phone come from the directory; edit the customer afterwards for anything else.', ', '))
                    ->schema(fn (DirectorySchool $record): array => [
                        TextInput::make('contact_person')->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(255)->default($record->phone),
                    ])
                    ->action(function (array $data, DirectorySchool $record, Action $action): void {
                        $customer = DomainErrorNotifier::attempt(
                            fn () => app(AddDirectorySchoolAsCustomer::class)->execute($record, $data),
                            $action,
                        );
                        Notification::make()->title("{$customer->name} added as {$customer->code}")->success()->send();
                    }),
                Action::make('linkCustomer')
                    ->label('Link existing customer')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (DirectorySchool $record): bool => $record->customer_id === null)
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Customer::query()->where('name', 'like', "%{$search}%")->orderBy('name')->limit(20)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value) => Customer::query()->whereKey($value)->value('name')),
                    ])
                    ->action(function (array $data, DirectorySchool $record, Action $action): void {
                        $customer = DomainErrorNotifier::attempt(
                            fn () => app(AddDirectorySchoolAsCustomer::class)->execute($record, [], (int) $data['customer_id']),
                            $action,
                        );
                        Notification::make()->title("{$record->name} linked to {$customer->code}")->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDirectorySchools::route('/')];
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
