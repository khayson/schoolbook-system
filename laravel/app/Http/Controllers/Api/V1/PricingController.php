<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PricingPreviewRequest;
use App\Models\Customer;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PricingController extends Controller
{
    public function preview(PricingPreviewRequest $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validated();
        $customer = isset($data['customer_id'])
            ? Customer::query()->findOrFail($data['customer_id'])
            : null;

        $priced = $pricing->priceLines(
            $customer,
            $data['items'],
            Carbon::parse($data['sale_date'] ?? now()),
        );

        return response()->json([
            'data' => $priced->toArray(),
        ]);
    }
}
