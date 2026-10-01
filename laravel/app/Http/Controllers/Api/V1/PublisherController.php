<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePublisherRequest;
use App\Http\Requests\Api\V1\UpdatePublisherRequest;
use App\Http\Resources\PublisherResource;
use App\Models\Publisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublisherController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Publisher::class);

        $search = $request->string('search')->trim()->toString();

        $query = Publisher::query()->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return PublisherResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StorePublisherRequest $request): PublisherResource
    {
        $publisher = Publisher::query()->create($request->validated());

        return new PublisherResource($publisher);
    }

    public function show(Publisher $publisher): PublisherResource
    {
        $this->authorize('view', $publisher);

        return new PublisherResource($publisher);
    }

    public function update(UpdatePublisherRequest $request, Publisher $publisher): PublisherResource
    {
        $publisher->update($request->validated());

        return new PublisherResource($publisher);
    }

    public function destroy(Publisher $publisher): PublisherResource
    {
        $this->authorize('delete', $publisher);

        $publisher->delete();

        return new PublisherResource($publisher);
    }
}
