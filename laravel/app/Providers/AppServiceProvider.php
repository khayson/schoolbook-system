<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
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
        // Short aliases only — never store FQCNs in morph columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'product' => Product::class,
            'customer' => Customer::class,
            'goods_receipt' => GoodsReceipt::class,
            'sale' => Sale::class,
            'payment' => 'App\Models\Payment',
            'stock_count' => 'App\Models\StockCount',
            'sale_return' => 'App\Models\SaleReturn',
            'purchase_order' => 'App\Models\PurchaseOrder',
        ]);
    }
}
