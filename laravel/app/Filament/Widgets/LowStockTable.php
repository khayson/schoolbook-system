<?php

namespace App\Filament\Widgets;

use App\Actions\Reports\LowStockReport;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;

/** The LowStockReport products, largest shortfall first. */
class LowStockTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Low stock';

    public static function canView(): bool
    {
        return Gate::allows('view-reports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => LowStockReport::products()
                ->select('*')
                ->selectRaw('reorder_level - stock_on_hand as shortfall')
                ->orderByDesc('shortfall')
                ->orderBy('title'))
            ->paginated([5])
            ->columns([
                TextColumn::make('sku')->label('SKU'),
                TextColumn::make('title')->wrap(),
                TextColumn::make('stock_on_hand')->label('On hand')->numeric()->alignEnd(),
                TextColumn::make('reorder_level')->label('Reorder')->numeric()->alignEnd(),
                TextColumn::make('status')
                    ->state(fn (Product $record): string => $record->stock_on_hand <= 0 ? 'Out of stock' : 'Low')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Out of stock' ? 'danger' : 'warning'),
            ])
            ->recordUrl(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]));
    }
}
