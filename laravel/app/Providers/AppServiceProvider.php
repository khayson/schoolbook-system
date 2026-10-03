<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\BackupGuard;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'stock_count' => 'App\Models\StockCount',
            'sale_return' => 'App\Models\SaleReturn',
            'purchase_order' => 'App\Models\PurchaseOrder',
        ]);
    }
}
