<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\Product;
use App\Services\PricingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class TestPriceRulePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Test pricing';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 21;

    protected static ?string $title = 'Test price rules';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->form->fill([
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'items' => [
                ['product_id' => null, 'quantity' => 1],
            ],
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('Customer')
                    ->searchable()
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->nullable(),
                DatePicker::make('sale_date')->required(),
                Repeater::make('items')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->searchable()
                            ->options(fn () => Product::query()->where('is_active', true)->orderBy('title')->pluck('title', 'id')->all())
                            ->required()
                            ->columnSpan(2),
                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(1),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->columnSpanFull(),
            ]);
    }

    public function runPreview(): void
    {
        $state = $this->form->getState();
        $customer = isset($state['customer_id'])
            ? Customer::query()->find($state['customer_id'])
            : null;

        try {
            $priced = app(PricingService::class)->priceLines(
                $customer,
                $state['items'],
                Carbon::parse($state['sale_date']),
            );
            $this->preview = $priced->toArray();
        } catch (\Throwable $e) {
            $this->preview = null;
            Notification::make()
                ->title('Preview failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('test-pricing-form')
                    ->livewireSubmitHandler('runPreview')
                    ->footer([
                        Actions::make([
                            Action::make('preview')
                                ->label('Run preview')
                                ->submit('runPreview'),
                        ])->alignment(Alignment::Start),
                    ]),
                Section::make('Result')
                    ->visible(fn (): bool => $this->preview !== null)
                    ->schema([
                        SchemaView::make('filament.pages.test-price-rule-result')
                            ->viewData(fn (): array => ['preview' => $this->preview]),
                    ]),
            ]);
    }
}
