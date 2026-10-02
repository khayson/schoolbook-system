<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\CancelSale;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateDraftSale;
use App\Actions\Sales\MarkDelivered;
use App\Actions\Sales\RenderInvoicePdf;
use App\Actions\Sales\UpdateDraftSale;
use App\Actions\Sales\VoidSale;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelSaleRequest;
use App\Http\Requests\Api\V1\ConfirmSaleRequest;
use App\Http\Requests\Api\V1\DeliverSaleRequest;
use App\Http\Requests\Api\V1\StoreDraftSaleRequest;
use App\Http\Requests\Api\V1\UpdateDraftSaleRequest;
use App\Http\Requests\Api\V1\VoidSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

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

        $sale->load(['customer', 'items.product', 'createdBy', 'allocations.payment']);

        return new SaleResource($sale);
    }

    public function update(UpdateDraftSaleRequest $request, Sale $sale, UpdateDraftSale $updateDraftSale): SaleResource
    {
        $sale = $updateDraftSale->execute($request->user(), $sale, $request->validated());

        return new SaleResource($sale);
    }

    public function confirm(ConfirmSaleRequest $request, Sale $sale, ConfirmSale $confirmSale): SaleResource
    {
        return new SaleResource($confirmSale->execute($request->user(), $sale, $request->validated()));
    }

    public function cancel(CancelSaleRequest $request, Sale $sale, CancelSale $cancelSale): SaleResource
    {
        return new SaleResource($cancelSale->execute($request->user(), $sale, $request->validated('reason')));
    }

    public function void(VoidSaleRequest $request, Sale $sale, VoidSale $voidSale): SaleResource
    {
        return new SaleResource($voidSale->execute($request->user(), $sale, $request->validated('reason')));
    }

    public function deliver(DeliverSaleRequest $request, Sale $sale, MarkDelivered $markDelivered): SaleResource
    {
        return new SaleResource($markDelivered->execute($request->user(), $sale));
    }

    public function invoice(Sale $sale, RenderInvoicePdf $renderInvoice): Response
    {
        $this->authorize('invoice', $sale);

        return $renderInvoice->execute($sale)->download($renderInvoice->filename($sale));
    }
}
