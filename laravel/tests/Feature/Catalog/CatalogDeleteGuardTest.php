<?php

use App\Actions\Inventory\ReceiveStock;
use App\Filament\Resources\Languages\Pages\EditLanguage;
use App\Filament\Resources\Levels\Pages\EditLevel;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Publishers\Pages\EditPublisher;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Subjects\Pages\EditSubject;
use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Publisher;
use App\Models\Subject;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->token = $this->owner->createToken('t')->plainTextToken;
});

test('a product with stock history cannot be deleted; an unused one can', function () {
    $used = Product::factory()->create();
    app(ReceiveStock::class)->execute($this->owner, [
        'received_at' => now(), 'items' => [['product_id' => $used->id, 'quantity' => 1, 'unit_cost' => 100]],
    ]);
    $unused = Product::factory()->create();

    $this->withToken($this->token)->deleteJson("/api/v1/products/{$used->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden')
        ->assertJsonPath('message', 'This record is in use. Deactivate it instead of deleting it.');

    $this->withToken($this->token)->deleteJson("/api/v1/products/{$unused->id}")->assertOk();

    expect(Product::query()->find($used->id))->not->toBeNull()
        ->and(Product::query()->find($unused->id))->toBeNull()
        ->and(Product::withTrashed()->find($unused->id))->not->toBeNull();
});

test('lookups used by any product (even a soft-deleted one) cannot be deleted', function (string $model, string $column, string $uri) {
    $record = $model::query()->findOrFail(Product::factory()->create()->{$column});
    Product::query()->where($column, $record->id)->get()->each->delete(); // only soft-deleted products left

    $this->withToken($this->token)->deleteJson("{$uri}/{$record->id}")->assertForbidden();

    expect($model::query()->find($record->id))->not->toBeNull();
})->with([
    'level' => [Level::class, 'level_id', '/api/v1/levels'],
    'subject' => [Subject::class, 'subject_id', '/api/v1/subjects'],
    'language' => [Language::class, 'language_id', '/api/v1/languages'],
]);

test('a publisher with books cannot be deleted; one without can', function () {
    $used = Publisher::query()->create(['name' => 'Used Press']);
    Product::factory()->create(['publisher_id' => $used->id]);
    $unused = Publisher::query()->create(['name' => 'Unused Press']);

    $this->withToken($this->token)->deleteJson("/api/v1/publishers/{$used->id}")->assertForbidden();
    $this->withToken($this->token)->deleteJson("/api/v1/publishers/{$unused->id}")->assertOk();
});

test('force delete and bulk delete are never allowed', function () {
    $product = Product::factory()->create();

    expect($this->owner->can('forceDelete', $product))->toBeFalse()
        ->and($this->owner->can('forceDeleteAny', Product::class))->toBeFalse()
        ->and($this->owner->can('deleteAny', Product::class))->toBeFalse()
        ->and($this->owner->can('restore', $product))->toBeTrue();
});

test('Filament edit pages offer no force delete, and hide delete while in use', function (string $page, Closure $make) {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$inUse, $free] = $make();

    Livewire::test($page, ['record' => $inUse->getRouteKey()])
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionHidden('delete');

    Livewire::test($page, ['record' => $free->getRouteKey()])
        ->assertActionVisible('delete');
})->with([
    'product' => [EditProduct::class, function () {
        $used = Product::factory()->create();
        app(ReceiveStock::class)->execute(User::query()->first(), [
            'received_at' => now(), 'items' => [['product_id' => $used->id, 'quantity' => 1, 'unit_cost' => 100]],
        ]);

        return [$used, Product::factory()->create()];
    }],
    'level' => [EditLevel::class, fn () => [
        Level::query()->findOrFail(Product::factory()->create()->level_id),
        Level::query()->create(['level_group_id' => Level::query()->value('level_group_id'), 'name' => 'Unused', 'slug' => 'unused-'.uniqid(), 'sort_order' => 99]),
    ]],
    'subject' => [EditSubject::class, fn () => [
        Subject::query()->findOrFail(Product::factory()->create()->subject_id),
        Subject::query()->create(['name' => 'Unused', 'slug' => 'unused-'.uniqid(), 'is_active' => true]),
    ]],
    'language' => [EditLanguage::class, fn () => [
        Language::query()->findOrFail(Product::factory()->create()->language_id),
        Language::query()->create(['name' => 'Unused', 'code' => 'zz'.random_int(10, 99), 'is_active' => true]),
    ]],
    'publisher' => [EditPublisher::class, function () {
        $used = Publisher::query()->create(['name' => 'Used Press']);
        Product::factory()->create(['publisher_id' => $used->id]);

        return [$used, Publisher::query()->create(['name' => 'Free Press'])];
    }],
]);

test('product list has no bulk delete', function () {
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListProducts::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
        ->assertActionDoesNotExist(TestAction::make('forceDelete')->table()->bulk());
});

test('the stock movement ledger has only list and view pages', function () {
    expect(array_keys(StockMovementResource::getPages()))->toBe(['index', 'view'])
        ->and(class_exists('App\\Filament\\Resources\\StockMovements\\Pages\\EditStockMovement'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\StockMovements\\Pages\\CreateStockMovement'))->toBeFalse();
});
