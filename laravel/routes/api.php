<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\LevelGroupController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublisherController;
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
        Route::apiResource('products', ProductController::class);

        Route::post('stock/receipts', [GoodsReceiptController::class, 'store'])
            ->middleware('idempotent');
        Route::get('stock/receipts', [GoodsReceiptController::class, 'index']);
        Route::get('stock/receipts/{goods_receipt}', [GoodsReceiptController::class, 'show']);
        Route::post('stock/adjustments', [StockAdjustmentController::class, 'store']);

        Route::apiResource('customers', CustomerController::class);

        Route::post('pricing/preview', [PricingController::class, 'preview']);

        Route::get('sales', [SaleController::class, 'index']);
        Route::post('sales', [SaleController::class, 'store']);
        Route::get('sales/{sale}', [SaleController::class, 'show']);
        Route::put('sales/{sale}', [SaleController::class, 'update']);

    });

});
