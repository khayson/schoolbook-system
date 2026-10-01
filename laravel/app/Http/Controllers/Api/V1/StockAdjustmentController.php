<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\AdjustStock;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockAdjustmentRequest;
use App\Http\Resources\StockMovementResource;
use Illuminate\Http\JsonResponse;

class StockAdjustmentController extends Controller
{
    public function store(StoreStockAdjustmentRequest $request, AdjustStock $adjustStock): JsonResponse
    {
        $validated = $request->validated();

        $movement = $adjustStock->execute(
            $request->user(),
            (int) $validated['product_id'],
            (int) $validated['quantity'],
            StockMovementType::from($validated['type']),
            $validated['note'],
        );

        return (new StockMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }
}
