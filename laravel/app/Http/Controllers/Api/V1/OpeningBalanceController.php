<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CreateOpeningBalance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOpeningBalanceRequest;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class OpeningBalanceController extends Controller
{
    public function store(StoreOpeningBalanceRequest $request, Customer $customer, CreateOpeningBalance $create): JsonResponse
    {
        $sale = $create->execute($request->user(), $customer, $request->validated());

        return (new SaleResource($sale->load(['customer', 'items'])))->response()->setStatusCode(201);
    }
}
