<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerCreditController;
use App\Http\Controllers\Api\V1\CustomerStatementController;
use App\Http\Controllers\Api\V1\DirectorySchoolController;
use App\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\LevelGroupController;
use App\Http\Controllers\Api\V1\OpeningBalanceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PriceRuleController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublisherController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReferenceBookController;
use App\Http\Controllers\Api\V1\ReferenceEditionController;
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockExchangeController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('auth/login', [AuthController::class, 'login'])

        ->middleware('throttle:5,1');

    Route::post('auth/register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
    Route::post('auth/forgot-password', [ForgotPasswordController::class, 'store'])->middleware('throttle:3,1');
    Route::post('auth/verify-setup-pin', [AuthController::class, 'verifySetupPin'])->middleware('throttle:10,1');
    Route::get('auth/status', [AuthController::class, 'status'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'owner'])->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('level-groups', [LevelGroupController::class, 'index']);

        Route::apiResource('levels', LevelController::class);

        Route::apiResource('subjects', SubjectController::class);

        Route::apiResource('languages', LanguageController::class);

        Route::apiResource('publishers', PublisherController::class);

        Route::get('products/by-code/{code}', [ProductController::class, 'byCode']);
        Route::get('products/{product}/movements', [ProductController::class, 'movements']);
        Route::post('products/attach-code', [ProductController::class, 'attachCode']);
        // Optional key: the 3.A.3 quick-create sends one; the Phase 1 form does not.
        Route::post('products', [ProductController::class, 'store'])
            ->middleware('idempotent:optional');
        Route::apiResource('products', ProductController::class)->except(['store']);

        // Approved (NaCCA) list, read-only
        Route::get('reference-books/snapshot', [ReferenceBookController::class, 'snapshot']);
        Route::get('reference-books', [ReferenceBookController::class, 'index']);
        Route::get('reference-editions/active', [ReferenceEditionController::class, 'active']);

        // Reports (owner): figures in pesewas, dates Africa/Accra, periods inclusive.
        Route::prefix('reports')->group(function () {
            Route::get('dashboard', [ReportController::class, 'dashboard']);
            Route::get('sales-summary', [ReportController::class, 'salesSummary']);
            Route::get('profit', [ReportController::class, 'profit']);
            Route::get('best-sellers', [ReportController::class, 'bestSellers']);
            Route::get('stock-valuation', [ReportController::class, 'stockValuation']);
            Route::get('low-stock', [ReportController::class, 'lowStock']);
            Route::get('dead-stock', [ReportController::class, 'deadStock']);
            Route::get('receivables-aging', [ReportController::class, 'receivablesAging']);
        });

        Route::post('stock/receipts', [GoodsReceiptController::class, 'store'])
            ->middleware('idempotent');
        Route::get('stock/receipts', [GoodsReceiptController::class, 'index']);
        Route::get('stock/receipts/{goods_receipt}', [GoodsReceiptController::class, 'show']);
        Route::post('stock/adjustments', [StockAdjustmentController::class, 'store']);

        // Stock-take (spec 6.3): owner; apply is idempotent and once only.
        Route::get('stock/counts', [StockCountController::class, 'index']);
        Route::post('stock/counts', [StockCountController::class, 'store']);
        Route::get('stock/counts/{stockCount}', [StockCountController::class, 'show']);
        Route::put('stock/counts/{stockCount}/items', [StockCountController::class, 'items']);
        Route::post('stock/counts/{stockCount}/apply', [StockCountController::class, 'apply'])
            ->middleware('idempotent');
        Route::post('stock/counts/{stockCount}/cancel', [StockCountController::class, 'cancel']);
        Route::get('stock/counts/{stockCount}/sheet', [StockCountController::class, 'sheet']);

        // Stock exchanges (dealer Taken / not-Taken swaps).
        Route::get('stock/exchanges', [StockExchangeController::class, 'index']);
        Route::post('stock/exchanges', [StockExchangeController::class, 'store'])
            ->middleware('idempotent');
        Route::get('stock/exchanges/{stockExchange}', [StockExchangeController::class, 'show']);
        Route::put('stock/exchanges/{stockExchange}', [StockExchangeController::class, 'update']);
        Route::post('stock/exchanges/{stockExchange}/confirm', [StockExchangeController::class, 'confirm'])
            ->middleware('idempotent');
        Route::post('stock/exchanges/{stockExchange}/cancel', [StockExchangeController::class, 'cancel']);
        Route::post('stock/exchanges/{stockExchange}/void', [StockExchangeController::class, 'void']);

        Route::apiResource('customers', CustomerController::class);
        Route::get('customers/{customer}/statement', [CustomerStatementController::class, 'show']);
        Route::post('customers/{customer}/opening-balance', [OpeningBalanceController::class, 'store'])
            ->middleware('idempotent');

        // School directory (OpenStreetMap): search, add a school as a customer.
        Route::get('school-directory', [DirectorySchoolController::class, 'index']);
        Route::post('school-directory/{directorySchool}/customer', [DirectorySchoolController::class, 'addAsCustomer'])
            ->middleware('idempotent');
        Route::post('customers/{customer}/apply-credit', [CustomerCreditController::class, 'apply'])
            ->middleware('idempotent');

        Route::post('pricing/preview', [PricingController::class, 'preview']);
        Route::apiResource('price-rules', PriceRuleController::class);

        Route::get('sales', [SaleController::class, 'index']);
        // Idempotent: a retried "save draft" must not create a second draft.
        Route::post('sales', [SaleController::class, 'store'])
            ->middleware('idempotent');
        Route::get('sales/{sale}', [SaleController::class, 'show']);
        Route::put('sales/{sale}', [SaleController::class, 'update']);
        Route::post('sales/{sale}/confirm', [SaleController::class, 'confirm'])
            ->middleware('idempotent');
        Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel']);
        Route::post('sales/{sale}/void', [SaleController::class, 'void']);
        Route::post('sales/{sale}/deliver', [SaleController::class, 'deliver']);
        Route::get('sales/{sale}/invoice', [SaleController::class, 'invoice']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware('idempotent');
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/void', [PaymentController::class, 'void']);
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);

        Route::get('sale-returns', [SaleReturnController::class, 'index']);
        Route::post('sale-returns', [SaleReturnController::class, 'store'])
            ->middleware('idempotent');
        Route::get('sale-returns/{saleReturn}', [SaleReturnController::class, 'show']);
        Route::post('sale-returns/{saleReturn}/confirm', [SaleReturnController::class, 'confirm'])
            ->middleware('idempotent');
        Route::post('sale-returns/{saleReturn}/void', [SaleReturnController::class, 'void']);

        Route::apiResource('suppliers', SupplierController::class);

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])
            ->middleware('idempotent');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
        Route::post('purchase-orders/{purchaseOrder}/place', [PurchaseOrderController::class, 'place']);
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])
            ->middleware('idempotent');

    });

});
