<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\UpdateDraftSale;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDraftSaleRequest;
use App\Http\Requests\Api\V1\UpdateDraftSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Sale::class);

        $query = Sale::query()
            ->with(['customer', 'items'])
            ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return SaleResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreDraftSaleRequest $request, CreateDraftSale $createDraftSale): JsonResponse
    {
        $sale = $createDraftSale->execute($request->user(), $request->validated());

        return (new SaleResource($sale))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Sale $sale): SaleResource
    {
        $this->authorize('view', $sale);

        $sale->load(['customer', 'items.product', 'createdBy']);

        return new SaleResource($sale);
    }

    public function update(UpdateDraftSaleRequest $request, Sale $sale, UpdateDraftSale $updateDraftSale): SaleResource
    {
        $sale = $updateDraftSale->execute($request->user(), $sale, $request->validated());

        return new SaleResource($sale);
    }
}
