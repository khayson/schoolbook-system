<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Actions\Inventory\ApplyStockCount;
use App\Actions\Inventory\CancelStockCount;
use App\Actions\Inventory\RenderStockCountSheet;
use App\Actions\Inventory\StockCountTotals;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\InteractsWithCurrentUser;
use App\Models\StockCount;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewStockCount extends ViewRecord
{
    use InteractsWithCurrentUser;

    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sheet')
                ->label('Print count sheet')
                ->icon('heroicon-o-printer')
                ->action(fn (StockCount $record): StreamedResponse => self::sheet($record)),
            Action::make('apply')
                ->label('Apply count')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (StockCount $record): bool => $record->isOpen())
                ->requiresConfirmation()
                ->modalHeading(fn (StockCount $record): string => "Apply {$record->reference}?")
                ->modalDescription(fn (StockCount $record): string => self::applySummary($record))
                ->modalSubmitActionLabel('Apply')
                ->action(function (StockCount $record, Action $action): void {
                    $applied = DomainErrorNotifier::attempt(
                        fn () => app(ApplyStockCount::class)->execute($this->getUser(), $record),
                        $action,
                    );
                    $this->record->refresh();
                    $t = app(StockCountTotals::class)->run($applied->fresh('items.product'));
                    Notification::make()
                        ->title("{$applied->reference} applied")
                        ->body('Net variance at cost '.Money::formatGhsGrouped($t['variance_value']))
                        ->success()
                        ->send();
                }),
            Action::make('cancel')
                ->label('Cancel count')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn (StockCount $record): bool => $record->isOpen())
                ->requiresConfirmation()
                ->modalDescription('Nothing is applied; the entered counts are kept for reference.')
                ->action(function (StockCount $record, Action $action): void {
                    DomainErrorNotifier::attempt(fn () => app(CancelStockCount::class)->execute($this->getUser(), $record), $action);
                    $this->record->refresh();
                    Notification::make()->title("{$record->reference} cancelled")->success()->send();
                }),
        ];
    }

    /** What applying will do, shown in the confirmation. */
    public static function applySummary(StockCount $record): string
    {
        $t = app(StockCountTotals::class)->run($record->fresh('items.product'));
        $changes = $record->items()->whereNotNull('counted_qty')->where('variance', '<>', 0)->count();

        return "{$t['counted']} of {$t['items']} products counted; {$changes} stock "
            .str('adjustment')->plural($changes).' of '.($t['variance_units'] > 0 ? '+' : '').$t['variance_units'].' units. '
            .'Net variance at cost '.Money::formatGhsGrouped($t['variance_value'])
            .' (losses '.Money::formatGhsGrouped($t['losses']).', gains '.Money::formatGhsGrouped($t['gains']).'). '
            .'Each adjustment is the variance measured when the count was entered; sales since then are kept. This cannot be undone.';
    }

    public static function sheet(StockCount $count): StreamedResponse
    {
        $render = app(RenderStockCountSheet::class);
        $pdf = $render->execute($count);

        return response()->streamDownload(fn () => print ($pdf->output()), $render->filename($count), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
