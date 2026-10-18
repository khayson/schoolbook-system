<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ConfirmSaleReturn;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\VoidSaleReturn;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmSaleReturnRequest;
use App\Http\Requests\Api\V1\StoreSaleReturnRequest;
use App\Http\Requests\Api\V1\VoidSaleReturnRequest;
use App\Http\Resources\SaleReturnResource;
use App\Models\SaleReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleReturnController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SaleReturn::class);

        $query = SaleReturn::query()
            ->with(['customer', 'items'])
            ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->integer('sale_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return SaleReturnResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StoreSaleReturnRequest $request, CreateSaleReturn $create): JsonResponse
    {
        $return = $create->execute($request->user(), $request->validated());

        return (new SaleReturnResource($return))->response()->setStatusCode(201);
    }

    public function show(SaleReturn $saleReturn): SaleReturnResource
    {
        $this->authorize('view', $saleReturn);

        $saleReturn->load(['customer', 'sale', 'items.product', 'createdBy']);

        return new SaleReturnResource($saleReturn);
    }

    public function confirm(
        ConfirmSaleReturnRequest $request,
        SaleReturn $saleReturn,
        ConfirmSaleReturn $confirm,
    ): SaleReturnResource {
        return new SaleReturnResource(
            $confirm->execute($request->user(), $saleReturn, $request->validated('reason')),
        );
    }

    public function void(
        VoidSaleReturnRequest $request,
        SaleReturn $saleReturn,
        VoidSaleReturn $void,
    ): SaleReturnResource {
        return new SaleReturnResource(
            $void->execute($request->user(), $saleReturn, $request->validated('reason')),
        );
    }
}
