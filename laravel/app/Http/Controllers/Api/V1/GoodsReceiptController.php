<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ReceiveStock;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $search = $request->string('search')->trim()->toString();

        $query = GoodsReceipt::query()
            ->with(['supplier', 'items.product'])
            ->orderByDesc('received_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('receipt_no', 'like', "%{$search}%")
                    ->orWhere('supplier_reference', 'like', "%{$search}%");
            });
        }

        return GoodsReceiptResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreGoodsReceiptRequest $request, ReceiveStock $receiveStock): JsonResponse
    {
        $receipt = $receiveStock->execute($request->user(), $request->validated());

        return (new GoodsReceiptResource($receipt))
            ->response()
            ->setStatusCode(201);
    }

    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt->load(['supplier', 'items.product', 'createdBy']);

        return new GoodsReceiptResource($goodsReceipt);
    }
}
