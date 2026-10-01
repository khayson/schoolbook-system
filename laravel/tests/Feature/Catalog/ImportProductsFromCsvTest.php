<?php

use App\Actions\Catalog\ImportProductsFromCsv;
use App\Enums\StockMovementType;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('import creates products and receives opening stock via movements', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::factory()->owner()->create();
    $level = Level::query()->where('slug', 'primary-1')->firstOrFail();
    $subject = Subject::query()->where('slug', 'mathematics')->firstOrFail();
    $language = Language::query()->where('code', 'en')->firstOrFail();

    $csv = implode("\n", [
        'title,level,subject,language,cost,price,opening_stock,sku',
        sprintf(
            'Imported Book,%s,%s,%s,12.50,18.00,7,CSV-TEST-001',
            $level->name,
            $subject->name,
            $language->name,
        ),
    ]);

    $result = app(ImportProductsFromCsv::class)->execute($user, $csv);

    expect($result['created'])->toBe(1)
        ->and($result['received_lines'])->toBe(1);

    $product = Product::query()->where('sku', 'CSV-TEST-001')->firstOrFail();

    expect($product->cost_price)->toBe(1250)
        ->and($product->selling_price)->toBe(1800)
        ->and($product->stock_on_hand)->toBe(7);

    $movement = StockMovement::query()
        ->where('product_id', $product->id)
        ->where('type', StockMovementType::ReceiptIn)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(7)
        ->and($movement->balance_after)->toBe(7);
});

test('import rejects ghs amounts with more than two decimals', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::factory()->owner()->create();
    $level = Level::query()->where('slug', 'primary-1')->firstOrFail();
    $subject = Subject::query()->where('slug', 'mathematics')->firstOrFail();
    $language = Language::query()->where('code', 'en')->firstOrFail();

    $csv = implode("\n", [
        'title,level,subject,language,cost,price,sku',
        sprintf(
            'Rounding Book,%s,%s,%s,10.005,9.994,CSV-ROUND-001',
            $level->name,
            $subject->name,
            $language->name,
        ),
    ]);

    expect(fn () => app(ImportProductsFromCsv::class)->execute($user, $csv))
        ->toThrow(InvalidArgumentException::class);
});

test('import resolves lookup names case insensitively', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::factory()->owner()->create();
    $level = Level::query()->where('slug', 'primary-1')->firstOrFail();
    $subject = Subject::query()->where('slug', 'mathematics')->firstOrFail();
    $language = Language::query()->where('code', 'en')->firstOrFail();

    $csv = implode("\n", [
        'title,level,subject,language,cost,price,sku',
        sprintf(
            'Case Book,%s,%s,%s,1,2,CSV-CASE-001',
            strtoupper($level->name),
            strtolower($subject->name),
            ucfirst($language->name),
        ),
    ]);

    app(ImportProductsFromCsv::class)->execute($user, $csv);

    expect(Product::query()->where('sku', 'CSV-CASE-001')->exists())->toBeTrue();
});
