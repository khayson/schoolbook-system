<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupplierRequest;
use App\Http\Requests\Api\V1\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Supplier::class);

        $search = $request->string('search')->trim()->toString();
        $query = Supplier::query()->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        return SupplierResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::query()->create($request->validated());

        return (new SupplierResource($supplier))->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        $this->authorize('view', $supplier);

        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier->fresh());
    }

    public function destroy(Supplier $supplier): SupplierResource
    {
        $this->authorize('delete', $supplier);
        $supplier->delete();

        return new SupplierResource($supplier);
    }
}
