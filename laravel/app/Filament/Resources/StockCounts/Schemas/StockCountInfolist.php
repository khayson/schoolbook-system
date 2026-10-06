<?php

namespace App\Filament\Resources\StockCounts\Schemas;

use App\Actions\Inventory\StockCountTotals;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Models\Language;
use App\Models\Level;
use App\Models\Publisher;
use App\Models\StockCount;
use App\Models\Subject;
use App\Services\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StockCountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $totals = fn (StockCount $record): array => app(StockCountTotals::class)->run($record->fresh('items.product'));

        return $schema->components([
            TextEntry::make('reference'),
            TextEntry::make('status')->badge()->color(fn (string $state): string => StockCountResource::statusColor($state)),
            TextEntry::make('filters')->label('Products')->state(fn (StockCount $record): string => self::describeFilters($record->filters)),
            TextEntry::make('created_at')->label('Opened')->dateTime(),
            TextEntry::make('counted')->label('Counted')
                ->state(fn (StockCount $record): string => ($t = $totals($record))['counted'].' of '.$t['items']),
            TextEntry::make('variance_units')->label('Variance (units)')->state(fn (StockCount $record): int => $totals($record)['variance_units']),
            TextEntry::make('losses')->label('Losses at cost')->state(fn (StockCount $record): string => Money::formatGhsGrouped($totals($record)['losses'])),
            TextEntry::make('gains')->label('Gains at cost')->state(fn (StockCount $record): string => Money::formatGhsGrouped($totals($record)['gains'])),
            TextEntry::make('variance_value')->label('Net variance at cost')->state(fn (StockCount $record): string => Money::formatGhsGrouped($totals($record)['variance_value'])),
            TextEntry::make('applied_at')->dateTime()->placeholder('Not applied'),
            TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
        ])->columns(4);
    }

    /** @param  array<string, int>|null  $filters */
    public static function describeFilters(?array $filters): string
    {
        if ($filters === null || $filters === []) {
            return 'All active products';
        }
        $names = [
            'level_id' => Level::class,
            'subject_id' => Subject::class,
            'language_id' => Language::class,
            'publisher_id' => Publisher::class,
        ];

        return collect($filters)
            ->map(fn (int $id, string $key): string => (string) ($names[$key]::query()->whereKey($id)->value('name') ?? "#{$id}"))
            ->implode(', ');
    }
}
