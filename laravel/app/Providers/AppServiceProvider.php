<?php

namespace App\Providers;

use App\Models\GoodsReceipt;
use App\Models\Product;
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
        // Future Phase 2+ models are registered now so rows stay stable when added.
        Relation::enforceMorphMap([
            'user' => User::class,
            'product' => Product::class,
            'goods_receipt' => GoodsReceipt::class,
            'sale' => 'App\Models\Sale',
            'payment' => 'App\Models\Payment',
            'stock_count' => 'App\Models\StockCount',
            'sale_return' => 'App\Models\SaleReturn',
            'purchase_order' => 'App\Models\PurchaseOrder',
        ]);
    }
}
