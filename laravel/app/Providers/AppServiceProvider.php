<?php

namespace App\Providers;

use App\Console\DestructiveDatabaseGuard;
use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Models\Sale;
use App\Models\User;
use App\Support\BackupGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Reports show the whole business's money: owner only.
        Gate::define('view-reports', fn (User $user): bool => $user->isOwner());

        // migrate:fresh / db:wipe only against a throwaway database (or on purpose).
        Event::listen(CommandStarting::class, [DestructiveDatabaseGuard::class, 'handle']);

        BackupGuard::check(
            $this->app->environment(),
            (array) config('backup.backup.destination.disks', []),
            config('backup.backup.password'),
        );

        // Short aliases only — never store FQCNs in morph columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'product' => Product::class,
            'customer' => Customer::class,
            'goods_receipt' => GoodsReceipt::class,
            'sale' => Sale::class,
            'payment' => Payment::class,
            'payment_allocation' => PaymentAllocation::class,
            'reference_book' => ReferenceBook::class,
            'reference_edition' => ReferenceEdition::class,
            'stock_count' => 'App\Models\StockCount',
            'sale_return' => 'App\Models\SaleReturn',
            'purchase_order' => 'App\Models\PurchaseOrder',
        ]);
    }
}
