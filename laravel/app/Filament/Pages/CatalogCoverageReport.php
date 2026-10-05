<?php

namespace App\Filament\Pages;

use App\Actions\Reports\CatalogCoverage;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ReferenceBooks\ReferenceBookActions;
use App\Models\Product;
use App\Models\ReferenceBook;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Catalog coverage: which approved titles the shop does not carry yet (what schools may
 * ask for), and which of its products are not on the approved list.
 */
class CatalogCoverageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|\UnitEnum|null $navigationGroup = 'Approved list';

    protected static ?string $navigationLabel = 'Coverage';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Catalog coverage';

    /** missing | not_on_list */
    #[Url]
    public string $show = 'missing';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', ReferenceBook::class);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.catalog-coverage-summary')
                ->viewData(fn (): array => ['summary' => app(CatalogCoverage::class)->summary()]),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showMissing')
                ->label('Not in my products')
                ->color(fn (): string => $this->show === 'missing' ? 'primary' : 'gray')
                ->action(fn () => $this->switchView('missing')),
            Action::make('showNotOnList')
                ->label('My products not on the list')
                ->color(fn (): string => $this->show === 'not_on_list' ? 'primary' : 'gray')
                ->action(fn () => $this->switchView('not_on_list')),
        ];
    }

    public function switchView(string $show): void
    {
        $this->show = $show === 'not_on_list' ? 'not_on_list' : 'missing';
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $coverage = app(CatalogCoverage::class);

        if ($this->show === 'not_on_list') {
            return $table
                ->heading('My products not on the approved list')
                ->query(fn () => $coverage->notOnList()->with(['level', 'subject', 'referenceBook:id,title,status']))
                ->defaultSort('title')
                ->columns([
                    TextColumn::make('sku')->label('SKU')->searchable(),
                    TextColumn::make('title')->searchable()->wrap(),
                    TextColumn::make('level.name')->label('Level'),
                    TextColumn::make('subject.name')->label('Subject'),
                    TextColumn::make('stock_on_hand')->label('Stock')->numeric(),
                    TextColumn::make('reason')
                        ->state(fn (Product $record): string => $record->reference_book_id === null ? 'Not linked' : 'Title withdrawn')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Title withdrawn' ? 'warning' : 'gray'),
                ])
                ->recordUrl(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]));
        }

        return $table
            ->heading('Approved titles not in my products')
            ->query(fn () => $coverage->missing()->with(['level', 'subject', 'publisher']))
            ->defaultSort('search_title')
            ->columns([
                TextColumn::make('title')->searchable(['title', 'search_title'])->wrap(),
                TextColumn::make('level')
                    ->state(fn (ReferenceBook $record): string => $record->level?->name
                        ?? CatalogCoverage::bandLabel($record->band)
                        ?? ReferenceBook::categoryLabel($record->category)),
                TextColumn::make('subject.name')->label('Subject')->placeholder('—'),
                TextColumn::make('publisher.name')->label('Publisher')->wrap(),
                TextColumn::make('category')->badge()->formatStateUsing(fn (string $state): string => ReferenceBook::categoryLabel($state)),
            ])
            ->filters([
                SelectFilter::make('level_id')->label('Level')->relationship('level', 'name'),
                SelectFilter::make('subject_id')->label('Subject')->relationship('subject', 'name'),
                SelectFilter::make('category')->options(collect(ReferenceBook::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => ReferenceBook::categoryLabel($c)])->all()),
            ])
            ->recordActions([ReferenceBookActions::addToProducts()]);
    }
}
