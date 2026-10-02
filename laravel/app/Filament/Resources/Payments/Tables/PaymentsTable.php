<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Services\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->columns([
                TextColumn::make('receipt_no')->label('Receipt')->searchable()->sortable(),
                TextColumn::make('customer.name')->searchable()->sortable(),
                TextColumn::make('paid_at')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('amount')->formatStateUsing($ghs)->alignEnd()->sortable(),
                TextColumn::make('unallocated_amount')->label('As credit')->formatStateUsing($ghs)->alignEnd(),
                TextColumn::make('method')->badge()->formatStateUsing(fn ($state): string => str($state?->value)->headline()->toString()),
                TextColumn::make('reference')->toggleable()->placeholder('-'),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state === PaymentRecordStatus::Void ? 'danger' : 'success'),
            ])
            ->defaultSort('paid_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    PaymentRecordStatus::Valid->value => 'Valid',
                    PaymentRecordStatus::Void->value => 'Void',
                ]),
                SelectFilter::make('method')->options(collect(PaymentMethod::cases())->mapWithKeys(
                    fn (PaymentMethod $m): array => [$m->value => str($m->value)->headline()->toString()],
                )->all()),
                SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable()->preload(),
                Filter::make('paid_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
