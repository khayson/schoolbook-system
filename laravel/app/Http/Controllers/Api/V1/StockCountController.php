<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ApplyStockCount;
use App\Actions\Inventory\CancelStockCount;
use App\Actions\Inventory\CreateStockCount;
use App\Actions\Inventory\EnterStockCount;
use App\Actions\Inventory\RenderStockCountSheet;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EnterStockCountRequest;
use App\Http\Requests\Api\V1\StoreStockCountRequest;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class StockCountController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockCount::class);

        $query = StockCount::query()->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return StockCountResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StoreStockCountRequest $request, CreateStockCount $create): JsonResponse
    {
        return (new StockCountResource($create->execute($request->user(), $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(StockCount $stockCount): StockCountResource
    {
        $this->authorize('view', $stockCount);

        return new StockCountResource($stockCount->load('items.product'));
    }

    public function items(EnterStockCountRequest $request, StockCount $stockCount, EnterStockCount $enter): StockCountResource
    {
        return new StockCountResource($enter->execute($stockCount, $request->validated('items')));
    }

    public function apply(Request $request, StockCount $stockCount, ApplyStockCount $apply): StockCountResource
    {
        $this->authorize('apply', $stockCount);

        return new StockCountResource($apply->execute($request->user(), $stockCount));
    }

    public function cancel(Request $request, StockCount $stockCount, CancelStockCount $cancel): StockCountResource
    {
        $this->authorize('cancel', $stockCount);

        return new StockCountResource($cancel->execute($request->user(), $stockCount));
    }

    public function sheet(StockCount $stockCount, RenderStockCountSheet $render): Response
    {
        $this->authorize('view', $stockCount);

        return $render->execute($stockCount)->download($render->filename($stockCount));
    }
}
