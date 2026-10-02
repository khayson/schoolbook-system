<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\AllocateCredit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyCreditRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PaymentAllocationResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerCreditController extends Controller
{
    public function apply(ApplyCreditRequest $request, Customer $customer, AllocateCredit $allocateCredit): JsonResponse
    {
        $result = $allocateCredit->execute($request->user(), $customer, $request->validated('allocations'));

        $allocations = collect($result->allocations)->each->load(['payment', 'sale']);

        return response()->json([
            'data' => [
                'applied_total' => $result->appliedTotal,
                'customer' => new CustomerResource($result->customer),
                'allocations' => PaymentAllocationResource::collection($allocations),
            ],
        ]);
    }
}
