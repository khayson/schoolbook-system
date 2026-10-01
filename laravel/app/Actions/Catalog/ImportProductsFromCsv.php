<?php

namespace App\Actions\Catalog;

use App\Actions\Inventory\ReceiveStock;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Subject;
use App\Models\User;
use App\Services\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ImportProductsFromCsv
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
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
                $level = $this->resolveLevel($row['level'], $lineNumber);
                $subject = $this->resolveSubject($row['subject'], $lineNumber);
                $language = $this->resolveLanguage($row['language'], $lineNumber);

                $sku = trim($row['sku'] ?? '');
                if ($sku === '') {
                    $sku = $this->generateSku();
                }

                if (Product::query()->where('sku', $sku)->exists()) {
                    throw new InvalidArgumentException("Row {$lineNumber}: SKU [{$sku}] already exists.");
                }

                $costPrice = Money::ghsToPesewas($row['cost']);
                $sellingPrice = Money::ghsToPesewas($row['price']);

                $product = Product::query()->create([
                    'sku' => $sku,
                    'title' => trim($row['title']),
                    'level_id' => $level->id,
                    'subject_id' => $subject->id,
                    'language_id' => $language->id,
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

        $required = ['title', 'level', 'subject', 'language', 'cost', 'price'];
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

            if (trim($row['title'] ?? '') === '') {
                throw new InvalidArgumentException("Row {$lineNumber}: title is required.");
            }

            $parsed[$lineNumber] = $row;
        }

        return $parsed;
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

    private function generateSku(): string
    {
        do {
            $sku = 'IMP-'.strtoupper(Str::random(8));
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
