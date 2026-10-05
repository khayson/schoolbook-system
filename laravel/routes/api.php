<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerCreditController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\LevelGroupController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublisherController;
use App\Http\Controllers\Api\V1\ReferenceBookController;
use App\Http\Controllers\Api\V1\ReferenceEditionController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\SubjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('auth/login', [AuthController::class, 'login'])

        ->middleware('throttle:5,1');

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

        Route::post('stock/receipts', [GoodsReceiptController::class, 'store'])
            ->middleware('idempotent');
        Route::get('stock/receipts', [GoodsReceiptController::class, 'index']);
        Route::get('stock/receipts/{goods_receipt}', [GoodsReceiptController::class, 'show']);
        Route::post('stock/adjustments', [StockAdjustmentController::class, 'store']);

        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/apply-credit', [CustomerCreditController::class, 'apply'])
            ->middleware('idempotent');

        Route::post('pricing/preview', [PricingController::class, 'preview']);

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

    });

});
