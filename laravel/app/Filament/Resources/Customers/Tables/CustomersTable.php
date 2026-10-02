<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\CustomerType;
use App\Enums\GhanaRegion;
use App\Services\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        $ghs = fn (?int $state): string => Money::formatGhsGrouped($state ?? 0);

        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('region')->toggleable(),
                TextColumn::make('district')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('outstanding_balance')->label('Owes')->formatStateUsing($ghs)->alignEnd()->sortable(),
                TextColumn::make('credit_balance')->label('Credit')->formatStateUsing($ghs)->alignEnd()->sortable(),
                TextColumn::make('credit_limit')->label('Limit')->formatStateUsing(fn (?int $state): string => $state === null ? 'None' : Money::formatGhsGrouped($state))->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('type')->options(collect(CustomerType::cases())->mapWithKeys(
                    fn (CustomerType $t): array => [$t->value => ucfirst($t->value)],
                )->all()),
                SelectFilter::make('region')->options(collect(GhanaRegion::cases())->mapWithKeys(
                    fn (GhanaRegion $r): array => [$r->value => $r->value],
                )->all())->searchable(),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('owes')->label('Owes money')->toggle()->query(fn (Builder $query): Builder => $query->where('outstanding_balance', '>', 0)),
                Filter::make('has_credit')->label('Has credit')->toggle()->query(fn (Builder $query): Builder => $query->where('credit_balance', '>', 0)),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
