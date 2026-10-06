<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\ReportsFixture;

/*
 * Loads the reports dataset (docs/acceptance-phase3.md section 1) into the throwaway
 * database schoolbook_test and leaves it there for the phone acceptance run (section 5).
 * The mysql group's beforeEach runs migrate:fresh on mysql_testing first, so this only
 * ever touches schoolbook_test. Run on purpose only:
 *
 *   php artisan test --group=phase3-phone --exclude-group=none
 */

test('the reports dataset is loaded into schoolbook_test for the phone run', function () {
    expect(config('database.connections.mysql_testing.database'))->toEndWith('_test');

    $this->seed(DatabaseSeeder::class);
    // The seeded owner (OWNER_EMAIL / OWNER_PASSWORD, as for the dev database) logs in on the phone.
    $owner = User::query()->where('role', 'owner')->sole();
    $fx = ReportsFixture::build($owner);

    expect(Product::query()->pluck('stock_on_hand', 'sku')->all())
        ->toBe(['RPT-A' => 86, 'RPT-B' => 44, 'RPT-C' => 34, 'RPT-D' => 20, 'RPT-E' => -1, 'RPT-F' => 9])
        ->and((int) Customer::query()->sum('outstanding_balance'))->toBe(49000)
        ->and($fx->customers['gamma']->fresh()->credit_balance)->toBe(2000);

    $this->artisan('customers:reconcile')->assertSuccessful();
    $this->artisan('stock:reconcile')->assertSuccessful();
})->group('phase3-phone');
