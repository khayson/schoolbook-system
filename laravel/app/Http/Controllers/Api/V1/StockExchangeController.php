<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\CancelStockExchange;
use App\Actions\Inventory\ConfirmStockExchange;
use App\Actions\Inventory\CreateStockExchange;
use App\Actions\Inventory\UpdateDraftStockExchange;
use App\Actions\Inventory\VoidStockExchange;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConfirmStockExchangeRequest;
use App\Http\Requests\Api\V1\StoreStockExchangeRequest;
use App\Http\Requests\Api\V1\UpdateStockExchangeRequest;
use App\Http\Requests\Api\V1\VoidStockExchangeRequest;
use App\Http\Resources\StockExchangeResource;
use App\Models\StockExchange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StockExchangeController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockExchange::class);

        $query = StockExchange::query()
            ->with(['customer', 'items'])
            ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return StockExchangeResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StoreStockExchangeRequest $request, CreateStockExchange $create): JsonResponse
    {
        $exchange = $create->execute($request->user(), $request->validated());

        return (new StockExchangeResource($exchange))->response()->setStatusCode(201);
    }

    public function show(StockExchange $stockExchange): StockExchangeResource
    {
        $this->authorize('view', $stockExchange);

        $stockExchange->load([
            'customer',
            'supplier',
            'items.product',
            'createdBy',
            'sale.items.product',
            'goodsReceipt.items.product',
        ]);

        return new StockExchangeResource($stockExchange);
    }

    public function update(
        UpdateStockExchangeRequest $request,
        StockExchange $stockExchange,
        UpdateDraftStockExchange $update,
    ): StockExchangeResource {
        return new StockExchangeResource(
            $update->execute($request->user(), $stockExchange, $request->validated()),
        );
    }

    public function confirm(
        ConfirmStockExchangeRequest $request,
        StockExchange $stockExchange,
        ConfirmStockExchange $confirm,
    ): StockExchangeResource {
        return new StockExchangeResource(
            $confirm->execute($request->user(), $stockExchange, $request->validated()),
        );
    }

    public function cancel(
        Request $request,
        StockExchange $stockExchange,
        CancelStockExchange $cancel,
    ): Response {
        $this->authorize('cancel', $stockExchange);
        $cancel->execute($request->user(), $stockExchange);

        return response()->noContent();
    }

    public function void(
        VoidStockExchangeRequest $request,
        StockExchange $stockExchange,
        VoidStockExchange $void,
    ): StockExchangeResource {
        return new StockExchangeResource(
            $void->execute($request->user(), $stockExchange, $request->validated('reason')),
        );
    }
}
