<?php

namespace App\Filament\Pages\Reports;

use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * A report page: a filter form (live) above the figures of one Report Action, rendered
 * by filament.pages.report-table. Owner only (gate view-reports). Figures and
 * definitions: docs/build-spec.md section 14, docs/acceptance-phase3.md.
 */
abstract class ReportPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public static function canAccess(): bool
    {
        return Gate::allows('view-reports');
    }

    public function mount(): void
    {
        $this->form->fill($this->defaultFilters());
    }

    /** @return array<string, mixed> */
    protected function defaultFilters(): array
    {
        return [];
    }

    /** @return list<Component|Field> */
    protected function filterFields(): array
    {
        return [];
    }

    /**
     * The report to show for the current filters.
     *
     * @return array{columns: array<string, array{0: string, 1: string}>, rows: list<array>, totals?: array|null, summary?: array<string, string>, note?: string|null}
     */
    abstract public function report(): array;

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('filters');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->filterFields())->columns(4);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...($this->filterFields() === [] ? [] : [EmbeddedSchema::make('form')]),
            View::make('filament.pages.report-table')->viewData(fn (): array => ['report' => $this->report()]),
        ]);
    }

    /** Filter state, after the form's own casting. */
    protected function filter(string $key, mixed $default = null): mixed
    {
        $value = $this->filters[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    protected static function dateField(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)->label($label)->native(false)->displayFormat('d M Y')->format('Y-m-d')->required()->live();
    }

    /** First day of this month and today, Africa/Accra. */
    protected static function thisMonth(): array
    {
        return ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()];
    }
}
