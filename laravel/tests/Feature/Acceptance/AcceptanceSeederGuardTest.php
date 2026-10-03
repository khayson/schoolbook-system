<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\AcceptanceSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\LevelGroupSeeder;
use Database\Seeders\LevelSeeder;
use Database\Seeders\SubjectSeeder;

beforeEach(function () {
    $this->seed([LevelGroupSeeder::class, LevelSeeder::class, SubjectSeeder::class, LanguageSeeder::class]);
    User::factory()->owner()->create();
});

test('the acceptance seeder refuses to run in production or staging', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    // Called directly: in production db:seed itself would first ask "Are you sure?".
    expect(fn () => app(AcceptanceSeeder::class)->run())
        ->toThrow(RuntimeException::class, "AcceptanceSeeder only runs in the local or testing environment (current: {$environment}).");

    expect(Product::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
})->with(['production', 'staging']);

test('the acceptance seeder runs in testing', function () {
    $this->seed(AcceptanceSeeder::class);

    expect(Product::query()->where('sku', 'like', 'ACC-%')->count())->toBe(3);
});
