<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StockMovementResource;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $search = $request->string('search')->trim()->toString();

        $query = Product::query()
            ->with(['level', 'subject', 'language', 'publisher'])
            ->orderBy('title');

        if ($request->filled('level_id')) {
            $query->where('level_id', $request->integer('level_id'));
        }

        if ($request->filled('level_group_id')) {
            $levelGroupId = $request->integer('level_group_id');
            $query->whereHas('level', fn ($builder) => $builder->where('level_group_id', $levelGroupId));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        if ($request->filled('language_id')) {
            $query->where('language_id', $request->integer('language_id'));
        }

        if ($request->filled('publisher_id')) {
            $query->where('publisher_id', $request->integer('publisher_id'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_on_hand', '<=', 'reorder_level');
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        return ProductResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;
        $data['reorder_level'] = $data['reorder_level'] ?? 0;

        // stock_on_hand defaults to 0 in the schema; never mass-assigned.
        $product = Product::query()->create($data);
        $product->refresh();
        $product->load(['level', 'subject', 'language', 'publisher']);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        $product->load(['level', 'subject', 'language', 'publisher']);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());
        $product->load(['level', 'subject', 'language', 'publisher']);

        return new ProductResource($product);
    }

    public function destroy(Product $product): ProductResource
    {
        $this->authorize('delete', $product);

        $product->delete();

        return new ProductResource($product);
    }

    public function byCode(string $code): ProductResource|JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $product = Product::query()
            ->with(['level', 'subject', 'language', 'publisher'])
            ->where('sku', $code)
            ->orWhere('isbn', $code)
            ->orWhere('barcode', $code)
            ->first();

        if ($product === null) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $this->authorize('view', $product);

        return new ProductResource($product);
    }

    public function movements(Request $request, Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return StockMovementResource::collection(
            StockMovement::query()
                ->where('product_id', $product->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request)),
        );
    }
}
