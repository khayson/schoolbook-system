<?php

use App\Models\Language;
use App\Models\Level;
use App\Models\Product;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('owner can create list filter and search products', function () {
    $this->seed(DatabaseSeeder::class);

    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $level = Level::query()->where('slug', 'primary-1')->firstOrFail();
    $subject = Subject::query()->where('slug', 'mathematics')->firstOrFail();
    $language = Language::query()->where('code', 'en')->firstOrFail();

    $create = $this->withToken($token)->postJson('/api/v1/products', [
        'sku' => 'MATH-P1-EN-001',
        'title' => 'Primary Mathematics Book One',
        'level_id' => $level->id,
        'subject_id' => $subject->id,
        'language_id' => $language->id,
        'cost_price' => 4500,
        'selling_price' => 6000,
        'reorder_level' => 5,
    ]);

    $create->assertCreated()
        ->assertJsonPath('data.sku', 'MATH-P1-EN-001')
        ->assertJsonPath('data.title', 'Primary Mathematics Book One')
        ->assertJsonPath('data.cost_price', 4500)
        ->assertJsonPath('data.selling_price', 6000)
        ->assertJsonPath('data.stock_on_hand', 0);

    Product::query()->whereKey($create->json('data.id'))->update(['stock_on_hand' => 20]);

    Product::factory()->create([
        'sku' => 'OTHER-SKU',
        'title' => 'Science Reader',
        'level_id' => $level->id,
        'subject_id' => Subject::query()->where('slug', 'science')->firstOrFail()->id,
        'language_id' => $language->id,
        'stock_on_hand' => 2,
        'reorder_level' => 10,
        'is_active' => false,
    ]);

    $this->withToken($token)->getJson('/api/v1/products?subject_id='.$subject->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'MATH-P1-EN-001');

    $this->withToken($token)->getJson('/api/v1/products?search=Mathematics')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Primary Mathematics Book One');

    $this->withToken($token)->getJson('/api/v1/products?low_stock=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'OTHER-SKU');

    $this->withToken($token)->getJson('/api/v1/products?active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'OTHER-SKU');
});

test('by-code finds product by sku', function () {
    $product = Product::factory()->create(['sku' => 'SCAN-ME-123']);

    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/products/by-code/SCAN-ME-123')
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.sku', 'SCAN-ME-123');
});

test('product endpoints require authentication', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
    $this->postJson('/api/v1/products', [])->assertUnauthorized();
});

test('cannot mass assign stock on hand via update', function () {
    $product = Product::factory()->create([
        'stock_on_hand' => 4,
        'reorder_level' => 2,
    ]);

    $user = User::factory()->owner()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/products/'.$product->id, [
        'stock_on_hand' => 999,
        'title' => 'Updated title',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_on_hand']);

    expect($product->fresh()->stock_on_hand)->toBe(4)
        ->and($product->fresh()->title)->not->toBe('Updated title');
});
