<?php

namespace App\Actions\Catalog;

use App\Actions\Inventory\ReceiveStock;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Publisher;
use App\Models\ReferenceBook;
use App\Models\Subject;
use App\Models\User;
use App\Services\Money;
use App\Services\ProductSkuGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Products from a CSV file (Products > Import CSV). Columns: title, level, subject,
 * language, cost, price; optional opening_stock, sku, publisher, variant_label and
 * reference_book_id. With reference_book_id (the "Create products" template exported
 * from Approved titles) blank title/level/subject/language/publisher come from the
 * approved title. All or nothing: one bad row and nothing is created.
 */
class ImportProductsFromCsv
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
        private readonly ProductSkuGenerator $skus,
    ) {}

    /**
     * @return array{created: int, received_lines: int}
     */
    public function execute(User $user, string $csvContent): array
    {
        $rows = $this->parseRows($csvContent);

        if ($rows === []) {
            throw new InvalidArgumentException('CSV file is empty or has no data rows.');
        }

        return DB::transaction(function () use ($user, $rows) {
            $created = 0;
            $receiveItems = [];

            foreach ($rows as $lineNumber => $row) {
                $data = $this->rowData($row, $lineNumber);

                $sku = trim($row['sku'] ?? '');
                if ($sku === '') {
                    $sku = $this->skus->next();
                }

                if (Product::withTrashed()->where('sku', $sku)->exists()) {
                    throw new InvalidArgumentException("Row {$lineNumber}: SKU [{$sku}] already exists.");
                }

                foreach (['cost', 'price'] as $money) {
                    if (trim((string) ($row[$money] ?? '')) === '') {
                        throw new InvalidArgumentException("Row {$lineNumber}: {$money} is required.");
                    }
                }
                $costPrice = Money::ghsToPesewas($row['cost']);
                $sellingPrice = Money::ghsToPesewas($row['price']);

                $product = Product::query()->create([
                    ...$data,
                    'sku' => $sku,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'reorder_level' => 0,
                    'is_active' => true,
                ]);

                $created++;

                $openingStock = (int) ($row['opening_stock'] ?? 0);
                if ($openingStock > 0) {
                    $receiveItems[] = [
                        'product_id' => $product->id,
                        'quantity' => $openingStock,
                        'unit_cost' => $costPrice,
                    ];
                }
            }

            $receivedLines = count($receiveItems);

            if ($receiveItems !== []) {
                $this->receiveStock->execute($user, [
                    'received_at' => now(),
                    'notes' => 'CSV import opening stock',
                    'items' => $receiveItems,
                ]);
            }

            return [
                'created' => $created,
                'received_lines' => $receivedLines,
            ];
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseRows(string $csvContent): array
    {
        $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];

        if ($lines === []) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = array_map(
            fn (string $header): string => strtolower(trim($header)),
            str_getcsv($headerLine),
        );

        // With reference_book_id the catalog columns may be blank (prefilled from the title).
        $required = in_array('reference_book_id', $headers, true)
            ? ['cost', 'price']
            : ['title', 'level', 'subject', 'language', 'cost', 'price'];
        foreach ($required as $column) {
            if (! in_array($column, $headers, true)) {
                throw new InvalidArgumentException("CSV is missing required column: {$column}.");
            }
        }

        $parsed = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $lineNumber = $index + 2;
            $values = str_getcsv($line);

            if (count($values) < count($headers)) {
                $values = array_pad($values, count($headers), '');
            }

            $row = array_combine($headers, array_slice($values, 0, count($headers)));

            if ($row === false) {
                throw new InvalidArgumentException("Row {$lineNumber}: could not parse CSV line.");
            }

            if (trim($row['title'] ?? '') === '' && trim($row['reference_book_id'] ?? '') === '') {
                throw new InvalidArgumentException("Row {$lineNumber}: title is required.");
            }

            $parsed[$lineNumber] = $row;
        }

        return $parsed;
    }

    /**
     * Catalog attributes of one row: names resolved to ids, blanks filled from the
     * approved title when the row has one.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function rowData(array $row, int $lineNumber): array
    {
        $cell = fn (string $column): string => trim((string) ($row[$column] ?? ''));

        $data = [
            'title' => $cell('title') ?: null,
            'level_id' => $cell('level') !== '' ? $this->resolveLevel($cell('level'), $lineNumber)->id : null,
            'subject_id' => $cell('subject') !== '' ? $this->resolveSubject($cell('subject'), $lineNumber)->id : null,
            'language_id' => $cell('language') !== '' ? $this->resolveLanguage($cell('language'), $lineNumber)->id : null,
            'publisher_id' => $cell('publisher') !== '' ? $this->resolvePublisher($cell('publisher'), $lineNumber)->id : null,
            'variant_label' => $cell('variant_label') ?: null,
            'reference_book_id' => null,
        ];

        if ($cell('reference_book_id') !== '') {
            $book = ctype_digit($cell('reference_book_id')) ? ReferenceBook::query()->find((int) $cell('reference_book_id')) : null;
            if ($book === null) {
                throw new InvalidArgumentException("Row {$lineNumber}: unknown approved title [{$cell('reference_book_id')}].");
            }
            $data['reference_book_id'] = $book->id;
            $data = PrefillFromReferenceBook::apply($data);
        }

        foreach (['title' => 'title', 'level_id' => 'level', 'subject_id' => 'subject', 'language_id' => 'language'] as $field => $column) {
            if ($data[$field] === null) {
                throw new InvalidArgumentException(
                    "Row {$lineNumber}: {$column} is required"
                    .($data['reference_book_id'] !== null ? ' (the approved title does not give one).' : '.')
                );
            }
        }

        return $data;
    }

    private function resolvePublisher(string $name, int $lineNumber): Publisher
    {
        $publisher = Publisher::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();

        if ($publisher === null) {
            throw new InvalidArgumentException("Row {$lineNumber}: unknown publisher [{$name}].");
        }

        return $publisher;
    }

    private function resolveLevel(string $name, int $lineNumber): Level
    {
        $level = Level::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();

        if ($level === null) {
            throw new InvalidArgumentException("Row {$lineNumber}: unknown level [{$name}].");
        }

        return $level;
    }

    private function resolveSubject(string $name, int $lineNumber): Subject
    {
        $subject = Subject::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();

        if ($subject === null) {
            throw new InvalidArgumentException("Row {$lineNumber}: unknown subject [{$name}].");
        }

        return $subject;
    }

    private function resolveLanguage(string $name, int $lineNumber): Language
    {
        $language = Language::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first();

        if ($language === null) {
            throw new InvalidArgumentException("Row {$lineNumber}: unknown language [{$name}].");
        }

        return $language;
    }
}
