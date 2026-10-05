<?php

use App\Actions\Catalog\ImportProductsFromCsv;
use App\Actions\Reports\CatalogCoverage;
use App\Filament\Pages\CatalogCoverageReport;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\ReferenceBooks\Pages\ListReferenceBooks;
use App\Filament\Resources\ReferenceBooks\ReferenceBookActions;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\ReferenceBook;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    publishFixtureList($this->owner);
});

function approved(string $title): ReferenceBook
{
    return ReferenceBook::query()->where('title', $title)->firstOrFail();
}

test('"Add to my products" opens prefilled and creates a linked product with opening stock', function () {
    $book = approved('Discover Science');

    Livewire::test(ListReferenceBooks::class)
        ->mountAction(TestAction::make('addToProducts')->table($book))
        ->assertSchemaStateSet([
            'level_id' => $book->level_id,
            'subject_id' => $book->subject_id,
            'language_id' => $book->language_id,
            'publisher_id' => $book->publisher_id,
            'opening_stock' => 0,
        ])
        ->setActionData(['variant_label' => "Learner's Book", 'cost_price' => '27.50', 'selling_price' => '1,040.00', 'opening_stock' => 12])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Added to your products');

    $product = Product::query()->where('reference_book_id', $book->id)->sole();
    expect($product->only(['title', 'variant_label', 'level_id', 'cost_price', 'selling_price', 'stock_on_hand']))->toBe([
        'title' => "Discover Science (Learner's Book)",
        'variant_label' => "Learner's Book",
        'level_id' => $book->level_id,
        'cost_price' => 2750,
        'selling_price' => 104000,
        'stock_on_hand' => 12,
    ])->and($product->sku)->toBe('BK-000001');
});

test('a band-only title needs the level chosen before it can be added', function () {
    $band = approved('Twi Kasa Workbook 1');

    Livewire::test(ListReferenceBooks::class)
        ->callAction(TestAction::make('addToProducts')->table($band), data: ['cost_price' => '10', 'selling_price' => '15'])
        ->assertHasActionErrors(['level_id' => 'required', 'language_id' => 'required']);

    Livewire::test(ListReferenceBooks::class)
        ->callAction(TestAction::make('addToProducts')->table($band), data: [
            'level_id' => Level::query()->where('slug', 'primary-2')->value('id'),
            'language_id' => Language::query()->where('code', 'tw-ak')->value('id'),
            'cost_price' => '10', 'selling_price' => '15',
        ])
        ->assertHasNoActionErrors();

    expect(Product::query()->where('reference_book_id', $band->id)->value('title'))->toBe('Twi Kasa Workbook 1');
});

test('the CSV template round trip creates linked products in bulk', function () {
    $books = ReferenceBook::query()->whereIn('title', ['Discover Science', 'Twi Kasa Workbook 1'])->orderBy('id')->get();

    $response = Livewire::test(ListReferenceBooks::class)
        ->selectTableRecords($books->modelKeys())
        ->callAction(TestAction::make('csvTemplate')->table()->bulk())
        ->assertFileDownloaded('products-from-approved-list.csv');

    $csv = base64_decode($response->effects['download']['content']);
    $lines = array_map('str_getcsv', preg_split('/\R/', trim($csv)));
    expect($lines[0])->toBe(ReferenceBookActions::TEMPLATE_COLUMNS)
        ->and($lines[1])->toBe([(string) $books[0]->id, 'Discover Science', '', 'Primary 4', 'Science', 'English', 'Baobab Publishing', '', '', '', ''])
        ->and($lines[2])->toBe([(string) $books[1]->id, 'Twi Kasa Workbook 1', '', '', 'Ghanaian Language', '', 'Odwira Publication', '', '', '', '']);

    // The owner fills it in: two variants of one title, a level and language for the band title.
    $filled = implode("\n", [
        implode(',', ReferenceBookActions::TEMPLATE_COLUMNS),
        "{$books[0]->id},,Learner's Book,,,,,,27.50,40.00,10",
        "{$books[0]->id},,Teacher's Guide,,,,,,35.00,55.00,2",
        "{$books[1]->id},,,Primary 2,,Twi (Asante),,,12.00,18.00,",
    ]);
    $result = app(ImportProductsFromCsv::class)->execute($this->owner, $filled);

    expect($result)->toBe(['created' => 3, 'received_lines' => 2])
        ->and(Product::query()->where('reference_book_id', $books[0]->id)->orderBy('id')->pluck('title')->all())
        ->toBe(["Discover Science (Learner's Book)", "Discover Science (Teacher's Guide)"])
        ->and(Product::query()->where('reference_book_id', $books[1]->id)->value('stock_on_hand'))->toBe(0)
        ->and(Product::query()->orderBy('id')->pluck('sku')->all())->toBe(['BK-000001', 'BK-000002', 'BK-000003']);

    // A band title left without a level: refused, nothing created.
    expect(fn () => app(ImportProductsFromCsv::class)->execute($this->owner, implode("\n", [
        implode(',', ReferenceBookActions::TEMPLATE_COLUMNS),
        "{$books[1]->id},,,,,,,,12.00,18.00,",
    ])))->toThrow(InvalidArgumentException::class, 'Row 2: level is required (the approved title does not give one).');
    expect(fn () => app(ImportProductsFromCsv::class)->execute($this->owner, implode("\n", [
        implode(',', ReferenceBookActions::TEMPLATE_COLUMNS),
        "{$books[0]->id},,,,,,,,,18.00,",
    ])))->toThrow(InvalidArgumentException::class, 'Row 2: cost is required.');
});

test('the products table shows Approved / Withdrawn / Not on list and filters on it', function () {
    $listed = stockedProduct(1, attributes: ['reference_book_id' => approved('Discover Science')->id]);
    $withdrawn = stockedProduct(1, attributes: ['reference_book_id' => approved('The Clever Tortoise')->id]);
    approved('The Clever Tortoise')->forceFill(['status' => 'withdrawn'])->save();
    $unlisted = stockedProduct(1);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('approved_list', 'Approved', $listed)
        ->assertTableColumnStateSet('approved_list', 'Withdrawn', $withdrawn)
        ->assertTableColumnStateSet('approved_list', 'Not on list', $unlisted)
        ->filterTable('approved_list', 'none')
        ->assertCanSeeTableRecords([$unlisted])
        ->assertCanNotSeeTableRecords([$listed, $withdrawn]);
});

test('the admin create page generates the SKU and can link an approved title', function () {
    $book = approved('Discover Science');

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'reference_book_id' => $book->id,
            'title' => 'Discover Science',
            'level_id' => $book->level_id,
            'subject_id' => $book->subject_id,
            'language_id' => $book->language_id,
            'cost_price' => '10',
            'selling_price' => '15',
            'reorder_level' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->sole()->only(['sku', 'reference_book_id']))->toBe(['sku' => 'BK-000001', 'reference_book_id' => $book->id]);
});

test('coverage figures match the hand count for the fixture', function () {
    stockedProduct(5, attributes: ['reference_book_id' => approved('Discover Science')->id]);
    stockedProduct(0, attributes: ['reference_book_id' => approved('Discover Science')->id]);
    stockedProduct(2, attributes: ['reference_book_id' => approved('Counting Fun for Kindergarten')->id]);
    stockedProduct(3); // not linked
    stockedProduct(1, attributes: ['reference_book_id' => approved('The Clever Tortoise')->id]);
    approved('The Clever Tortoise')->forceFill(['status' => 'withdrawn'])->save();
    stockedProduct(9, attributes: ['reference_book_id' => approved('Science Around Us')->id])->delete(); // deleted: does not count

    $summary = app(CatalogCoverage::class)->summary();

    expect($summary['totals'])->toBe(['approved' => 10, 'in_products' => 2, 'missing' => 8, 'not_on_list' => 2])
        ->and($summary['levels'])->toBe([
            ['group' => 'KG 2', 'approved' => 1, 'in_products' => 1, 'missing' => 0],
            ['group' => 'Primary 1', 'approved' => 1, 'in_products' => 0, 'missing' => 1],
            ['group' => 'Primary 2', 'approved' => 3, 'in_products' => 0, 'missing' => 3],
            ['group' => 'Primary 3', 'approved' => 1, 'in_products' => 0, 'missing' => 1],
            ['group' => 'Primary 4', 'approved' => 1, 'in_products' => 1, 'missing' => 0],
            ['group' => 'JHS 1', 'approved' => 1, 'in_products' => 0, 'missing' => 1],
            ['group' => 'JHS 2', 'approved' => 1, 'in_products' => 0, 'missing' => 1],
            ['group' => 'Lower Primary', 'approved' => 1, 'in_products' => 0, 'missing' => 1],
        ])
        ->and(collect($summary['subjects'])->firstWhere('group', 'Primary 2'))
        ->toBe(['group' => 'Primary 2', 'subject' => 'Mathematics', 'approved' => 3, 'in_products' => 0, 'missing' => 3]);

    Livewire::test(CatalogCoverageReport::class)
        ->assertOk()
        ->assertSeeHtml('data-coverage="missing">8<')
        ->assertCountTableRecords(8)
        ->assertCanNotSeeTableRecords([approved('Discover Science'), approved('The Clever Tortoise')])
        ->callAction('showNotOnList')
        ->assertCountTableRecords(2);
});
