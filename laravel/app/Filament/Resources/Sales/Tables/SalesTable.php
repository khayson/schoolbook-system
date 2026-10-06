<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Filament\Resources\Sales\Schemas\SaleInfolist;
use App\Services\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->columns([
                TextColumn::make('invoice_no')->label('Invoice')->placeholder('Draft')->searchable()->sortable()
                    ->description(fn ($record): ?string => $record->is_opening_balance ? 'Balance brought forward' : null),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => SaleInfolist::statusColor($state)),
                TextColumn::make('payment_status')->label('Payment')->badge()
                    ->color(fn ($state): string => match ($state) {
                        PaymentStatus::Paid => 'success',
                        PaymentStatus::Partial => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('sale_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable()->placeholder('-'),
                TextColumn::make('total')->formatStateUsing($ghs)->alignEnd()->sortable(),
                TextColumn::make('balance_due')->label('Balance')->formatStateUsing($ghs)->alignEnd()->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(SaleStatus::cases())->mapWithKeys(
                    fn (SaleStatus $s): array => [$s->value => ucfirst($s->value)],
                )->all()),
                SelectFilter::make('payment_status')->label('Payment')->options(collect(PaymentStatus::cases())->mapWithKeys(
                    fn (PaymentStatus $s): array => [$s->value => ucfirst($s->value)],
                )->all()),
                SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable()->preload(),
                Filter::make('sale_date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('sale_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('sale_date', '<=', $date))),
                Filter::make('overdue')
                    ->label('Overdue')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', SaleStatus::Confirmed)
                        ->where('balance_due', '>', 0)
                        ->whereDate('due_date', '<', now()->toDateString())),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
