<?php

namespace App\Filament\Resources\StockCounts\RelationManagers;

use App\Actions\Inventory\EnterStockCount;
use App\Filament\Support\DomainErrorNotifier;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inline count entry and variance review. Each entry goes through EnterStockCount, which
 * records the system quantity at that moment.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Products';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var StockCount $count */
        $count = $this->getOwnerRecord();
        $ghs = fn (?int $state): string => $state === null ? '-' : Money::formatGhsGrouped($state);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product')
                ->join('products', 'products.id', '=', 'stock_count_items.product_id')
                ->select('stock_count_items.*')
                ->orderBy('products.sku'))
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('product.sku')->label('SKU')->searchable(query: fn (Builder $q, string $search) => $q->where('products.sku', 'like', "%{$search}%")->orWhere('products.title', 'like', "%{$search}%")),
                TextColumn::make('product.title')->label('Title')->wrap(),
                TextInputColumn::make('counted_qty')
                    ->label('Counted')
                    ->type('number')
                    ->rules(['nullable', 'integer', 'min:0', 'max:1000000'])
                    ->disabled(! $count->isOpen())
                    ->updateStateUsing(function (StockCountItem $record, $state) use ($count): ?int {
                        $value = $state === null || $state === '' ? null : (int) $state;
                        DomainErrorNotifier::attempt(fn () => app(EnterStockCount::class)->execute($count, [
                            ['product_id' => $record->product_id, 'counted_qty' => $value],
                        ]));

                        return $value;
                    }),
                TextColumn::make('system_qty')->label('System')->numeric()->placeholder('-')->alignEnd(),
                TextColumn::make('variance')->numeric()->placeholder('-')->alignEnd()
                    ->color(fn (?int $state): ?string => $state === null || $state === 0 ? null : ($state < 0 ? 'danger' : 'success')),
                TextColumn::make('variance_value')->label('At cost')->alignEnd()
                    ->state(fn (StockCountItem $record): ?int => $record->variance === null ? null : $record->variance * ($record->unit_cost ?? (int) $record->product->cost_price))
                    ->formatStateUsing($ghs),
                TextColumn::make('counted_at')->label('Counted at')->dateTime('d M H:i')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Show')
                    ->options(['uncounted' => 'Not counted yet', 'counted' => 'Counted', 'variance' => 'With a variance'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'uncounted' => $query->whereNull('stock_count_items.counted_qty'),
                        'counted' => $query->whereNotNull('stock_count_items.counted_qty'),
                        'variance' => $query->whereNotNull('stock_count_items.counted_qty')->where('stock_count_items.variance', '<>', 0),
                        default => $query,
                    }),
            ]);
    }
}
