<?php

namespace App\Filament\Resources\Sales\Schemas;

use App\DTOs\Pricing\PricedOrder;
use App\Exceptions\InvalidInputException;
use App\Filament\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Support\GhsInput;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Subject;
use App\Services\Money;
use App\Services\PricingService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Draft sale form. Prices shown here are read-only previews from PricingService (the
 * same service CreateDraftSale/UpdateDraftSale use); the form never computes money.
 */
class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Select::make('customer_id')
                    ->label('Customer')
                    ->options(fn (): array => Customer::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get): ?string => PaymentForm::balancesLine($get('customer_id'))),
                DatePicker::make('sale_date')->required()->default(fn () => now())->live(),
                DatePicker::make('due_date')->helperText('Optional; otherwise set from payment terms at confirmation.'),
                Textarea::make('notes')->columnSpanFull(),
                Repeater::make('items')
                    ->label('Books')
                    ->schema(self::lineSchema())
                    ->columns(6)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->live()
                    ->columnSpanFull(),
                Placeholder::make('order_total')
                    ->label('Order total (preview)')
                    ->content(fn (Get $get): string => self::totalLine($get))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<Component>
     */
    private static function lineSchema(): array
    {
        return [
            Select::make('level_id')
                ->label('Level')
                ->options(fn (): array => Level::query()->orderBy('sort_order')->pluck('name', 'id')->all())
                ->placeholder('Any level')
                ->dehydrated(false)
                ->live(),
            Select::make('subject_id')
                ->label('Subject')
                ->options(fn (): array => Subject::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder('Any subject')
                ->dehydrated(false)
                ->live(),
            Select::make('language_id')
                ->label('Language')
                ->options(fn (): array => Language::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder('Any language')
                ->dehydrated(false)
                ->live(),
            Select::make('product_id')
                ->label('Book')
                ->options(fn (Get $get): array => self::productOptions($get('level_id'), $get('subject_id'), $get('language_id')))
                ->searchable()
                ->required()
                ->live()
                ->columnSpan(3),
            TextInput::make('quantity')
                ->integer()
                ->minValue(1)
                ->default(1)
                ->required()
                ->live(onBlur: true),
            GhsInput::make('override_unit_price')
                ->label('Override unit price')
                ->live(onBlur: true)
                ->columnSpan(2),
            TextInput::make('override_reason')
                ->label('Override reason')
                ->maxLength(255)
                ->required(fn (Get $get): bool => GhsInput::toPesewas($get('override_unit_price')) !== null)
                ->columnSpan(2),
            Placeholder::make('line_price')
                ->label('Price')
                ->content(fn (Get $get): string => self::linePriceLine($get)),
        ];
    }

    /**
     * Active books, narrowed by the row's level/subject/language chips; the label shows stock.
     *
     * @return array<int, string>
     */
    public static function productOptions(mixed $levelId = null, mixed $subjectId = null, mixed $languageId = null): array
    {
        return Product::query()
            ->where('is_active', true)
            ->when($levelId, fn (Builder $q, $id) => $q->where('level_id', $id))
            ->when($subjectId, fn (Builder $q, $id) => $q->where('subject_id', $id))
            ->when($languageId, fn (Builder $q, $id) => $q->where('language_id', $id))
            ->orderBy('title')
            ->limit(500)
            ->get(['id', 'title', 'sku', 'stock_on_hand'])
            ->mapWithKeys(fn (Product $p): array => [$p->id => "{$p->title} ({$p->sku}) | stock {$p->stock_on_hand}"])
            ->all();
    }

    /**
     * Form repeater state -> PricingService / draft action lines. Incomplete rows are skipped.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<array{product_id: int, quantity: int, override_unit_price: int|null, override_reason: string|null}>
     */
    public static function linesFromState(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            if (! is_numeric($item['product_id'] ?? null) || ! is_numeric($item['quantity'] ?? null) || (int) $item['quantity'] < 1) {
                continue;
            }

            $override = is_int($item['override_unit_price'] ?? null)
                ? $item['override_unit_price']
                : GhsInput::toPesewas($item['override_unit_price'] ?? null);

            $lines[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'override_unit_price' => $override,
                'override_reason' => $override !== null ? ($item['override_reason'] ?? null) : null,
            ];
        }

        return $lines;
    }

    private static function preview(mixed $customerId, mixed $saleDate, array $lines): ?PricedOrder
    {
        if ($lines === []) {
            return null;
        }

        try {
            return app(PricingService::class)->priceLines(
                is_numeric($customerId) ? Customer::query()->find((int) $customerId) : null,
                $lines,
                Carbon::parse($saleDate ?: now()),
            );
        } catch (InvalidInputException) {
            return null; // e.g. override without a reason yet: the field itself shows the error
        }
    }

    private static function linePriceLine(Get $get): string
    {
        $lines = self::linesFromState([[
            'product_id' => $get('product_id'),
            'quantity' => $get('quantity'),
            'override_unit_price' => $get('override_unit_price'),
            'override_reason' => $get('override_reason') ?: 'preview',
        ]]);
        $line = self::preview($get('../../customer_id'), $get('../../sale_date'), $lines)?->lines[0] ?? null;

        if ($line === null) {
            return '-';
        }

        $text = Money::formatGhsGrouped($line->unitPrice).' x '.$line->quantity.' = '.Money::formatGhsGrouped($line->lineTotal);

        return $line->isPriceOverridden ? $text.' (list '.Money::formatGhsGrouped($line->basePrice).')' : $text;
    }

    private static function totalLine(Get $get): string
    {
        $order = self::preview($get('customer_id'), $get('sale_date'), self::linesFromState($get('items') ?? []));

        return $order === null ? '-' : Money::formatGhsGrouped($order->total).' for '.array_sum(array_map(fn ($l) => $l->quantity, $order->lines)).' book(s)';
    }
}
