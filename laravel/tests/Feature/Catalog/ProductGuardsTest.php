<?php

use App\Models\Product;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('product price and cost changes are activity-logged', function () {
    $user = User::factory()->owner()->create();
    $this->actingAs($user);

    $product = Product::factory()->create([
        'cost_price' => 1000,
        'selling_price' => 2000,
    ]);

    $product->update([
        'cost_price' => 1500,
        'selling_price' => 2500,
    ]);

    $activity = Activity::query()
        ->where('subject_type', $product->getMorphClass())
        ->where('subject_id', $product->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->attribute_changes['attributes']['cost_price'] ?? null)->toBe(1500)
        ->and($activity->attribute_changes['attributes']['selling_price'] ?? null)->toBe(2500)
        ->and($activity->attribute_changes['old']['cost_price'] ?? null)->toBe(1000)
        ->and($activity->attribute_changes['old']['selling_price'] ?? null)->toBe(2000);
});

test('stock_on_hand is not mass assignable on product', function () {
    $product = Product::factory()->create(['stock_on_hand' => 5]);

    $product->update(['stock_on_hand' => 999, 'title' => 'Renamed']);

    expect($product->fresh()->stock_on_hand)->toBe(5)
        ->and($product->fresh()->title)->toBe('Renamed');
});
