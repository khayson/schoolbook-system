<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;

class TopOwingCustomers extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Who owes most';

    public static function canView(): bool
    {
        return Gate::allows('view-reports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Customer::query()->where('outstanding_balance', '>', 0))
            ->defaultSort('outstanding_balance', 'desc')
            ->paginated([5])
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('outstanding_balance')->label('Owes')->alignEnd()
                    ->formatStateUsing(fn (int $state): string => Money::formatGhsGrouped($state)),
            ])
            ->recordUrl(fn (Customer $record): string => CustomerResource::getUrl('edit', ['record' => $record]));
    }
}
