<?php

namespace App\Filament\Resources\ReferenceEditions\Tables;

use App\Actions\Reference\DiscardReferenceEdition;
use App\Filament\Resources\ReferenceEditions\ReferenceEditionResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Models\ReferenceEdition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class ReferenceEditionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('label')->weight('bold'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => $state === 'draft' ? 'Waiting for review' : ucfirst($state)),
                TextColumn::make('rows_total')->label('Rows')->numeric(),
                TextColumn::make('rows_new')->label('New')->numeric(),
                TextColumn::make('rows_changed')->label('Changed')->numeric(),
                TextColumn::make('rows_removed')->label('Removed')->numeric(),
                TextColumn::make('rows_with_issues')->label('To check')->numeric(),
                TextColumn::make('rows_skipped')->label('Skipped')->numeric()
                    ->tooltip(fn (ReferenceEdition $record): ?string => collect($record->skipped ?? [])
                        ->map(fn (array $s) => "p.{$s['page']} {$s['section']} #{$s['serial']}: {$s['reason']}")
                        ->implode("\n") ?: null),
                TextColumn::make('created_at')->label('Imported')->dateTime(),
                TextColumn::make('activated_at')->label('Published')->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (ReferenceEdition $record): bool => $record->isDraft())
                    ->url(fn (ReferenceEdition $record): string => ReferenceEditionResource::getUrl('review', ['record' => $record])),
                Action::make('discard')
                    ->label('Discard')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->visible(fn (ReferenceEdition $record): bool => Gate::allows('discard', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The staged rows and your review decisions are deleted. The live list is not affected.')
                    ->action(function (ReferenceEdition $record, Action $action): void {
                        $user = Filament::auth()->user();
                        assert($user instanceof User);
                        DomainErrorNotifier::attempt(fn () => app(DiscardReferenceEdition::class)->execute($user, $record), $action);
                        Notification::make()->title('Import discarded')->success()->send();
                    }),
            ]);
    }
}
