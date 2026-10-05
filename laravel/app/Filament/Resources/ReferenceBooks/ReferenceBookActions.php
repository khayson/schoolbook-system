<?php

namespace App\Filament\Resources\ReferenceBooks;

use App\Actions\Catalog\CreateProduct;
use App\Actions\Catalog\PrefillFromReferenceBook;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\DomainErrorNotifier;
use App\Filament\Support\GhsInput;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Publisher;
use App\Models\ReferenceBook;
use App\Models\Subject;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Approved titles → the shop's products: one at a time (prefilled form) or in bulk
 * (CSV template, filled in a spreadsheet, imported with Products > Import CSV).
 */
final class ReferenceBookActions
{
    public const TEMPLATE_COLUMNS = ['reference_book_id', 'title', 'variant_label', 'level', 'subject', 'language', 'publisher', 'sku', 'cost', 'price', 'opening_stock'];

    public static function addToProducts(): Action
    {
        return Action::make('addToProducts')
            ->label('Add to my products')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn (): bool => Gate::allows('create', Product::class))
            ->modalHeading(fn (ReferenceBook $record): string => 'Add "'.$record->title.'"')
            ->modalDescription('Title, level, subject, language and publisher come from the approved list. Change any of them if your copy differs.')
            ->fillForm(fn (ReferenceBook $record): array => [
                ...array_intersect_key(
                    PrefillFromReferenceBook::apply(['reference_book_id' => $record->id]),
                    array_flip(['level_id', 'subject_id', 'language_id', 'publisher_id']),
                ),
                'opening_stock' => 0,
            ])
            ->schema([
                TextInput::make('variant_label')->label('Variant')->maxLength(100)
                    ->placeholder("Learner's Book, Teacher's Guide, Workbook…")
                    ->helperText('Leave blank if you stock only one version of this title.'),
                TextInput::make('title')->maxLength(255)
                    ->placeholder(fn (ReferenceBook $record): string => $record->title)
                    ->helperText('Blank: the approved title, with the variant in brackets.'),
                Select::make('level_id')->label('Level')->required()
                    ->options(fn () => Level::query()->orderBy('level_group_id')->orderBy('sort_order')->pluck('name', 'id'))
                    ->helperText(fn (ReferenceBook $record): ?string => $record->level_id === null && $record->level_label !== null
                        ? "Listed for {$record->level_label}: choose the class." : null),
                Select::make('subject_id')->label('Subject')->required()->searchable()
                    ->options(fn () => Subject::query()->orderBy('name')->pluck('name', 'id')),
                Select::make('language_id')->label('Language')->required()
                    ->options(fn () => Language::query()->orderBy('name')->pluck('name', 'id')),
                Select::make('publisher_id')->label('Publisher')->searchable()
                    ->options(fn () => Publisher::query()->orderBy('name')->pluck('name', 'id')),
                TextInput::make('sku')->label('SKU')->maxLength(255)->unique('products', 'sku')
                    ->helperText('Blank: generated (BK-000123).'),
                GhsInput::make('cost_price')->label('Cost price')->required(),
                GhsInput::make('selling_price')->label('Selling price')->required(),
                TextInput::make('opening_stock')->label('Opening stock')->integer()->minValue(0)->maxValue(100000)->default(0)
                    ->helperText('Received into stock at the cost price.'),
            ])
            ->action(function (ReferenceBook $record, array $data, Action $action): void {
                $user = Filament::auth()->user();
                assert($user instanceof User);
                $product = DomainErrorNotifier::attempt(fn () => app(CreateProduct::class)->execute(
                    $user,
                    PrefillFromReferenceBook::apply([...$data, 'reference_book_id' => $record->id]),
                ), $action);

                Notification::make()
                    ->title('Added to your products')
                    ->body("{$product->title} ({$product->sku}), stock {$product->stock_on_hand}.")
                    ->success()
                    ->actions([Action::make('open')->label('Open product')->url(ProductResource::getUrl('edit', ['record' => $product]))])
                    ->send();
            });
    }

    public static function csvTemplate(): BulkAction
    {
        return BulkAction::make('csvTemplate')
            ->label('Create products (CSV template)')
            ->icon('heroicon-o-document-arrow-down')
            ->modalDescription('Downloads a CSV with the selected titles. Fill in cost, price and opening stock (one line per variant), then upload it in Products > Import CSV.')
            ->action(fn (Collection $records): StreamedResponse => response()->streamDownload(function () use ($records): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, self::TEMPLATE_COLUMNS);
                $records->load(['level', 'subject', 'language', 'publisher']);
                foreach ($records as $book) {
                    fputcsv($out, [
                        $book->id,
                        $book->title,
                        '',
                        $book->level?->name ?? '',
                        $book->subject?->name ?? '',
                        $book->language?->name ?? '',
                        $book->publisher?->name ?? '',
                        '', '', '', '',
                    ]);
                }
                fclose($out);
            }, 'products-from-approved-list.csv', ['Content-Type' => 'text/csv']));
    }
}
