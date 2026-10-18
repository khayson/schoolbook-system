<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\ReceiveAgainstPurchaseOrder;
use App\Actions\Purchasing\UpdateDraftPurchaseOrder;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReceivePurchaseOrderRequest;
use App\Http\Requests\Api\V1\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\UpdatePurchaseOrderRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $query = PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return PurchaseOrderResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrder $create): JsonResponse
    {
        $po = $create->execute($request->user(), $request->validated());

        return (new PurchaseOrderResource($po))->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'items.product', 'createdBy']);

        return new PurchaseOrderResource($purchaseOrder);
    }

    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        UpdateDraftPurchaseOrder $update,
    ): PurchaseOrderResource {
        return new PurchaseOrderResource(
            $update->execute($request->user(), $purchaseOrder, $request->validated()),
        );
    }

    public function place(Request $request, PurchaseOrder $purchaseOrder, PlacePurchaseOrder $place): PurchaseOrderResource
    {
        $this->authorize('place', $purchaseOrder);

        return new PurchaseOrderResource($place->execute($request->user(), $purchaseOrder));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $cancel): PurchaseOrderResource
    {
        $this->authorize('cancel', $purchaseOrder);

        return new PurchaseOrderResource($cancel->execute($request->user(), $purchaseOrder));
    }

    public function receive(
        ReceivePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        ReceiveAgainstPurchaseOrder $receive,
    ): JsonResponse {
        $receipt = $receive->execute($request->user(), $purchaseOrder, $request->validated());

        return (new GoodsReceiptResource($receipt))->response()->setStatusCode(201);
    }
}
