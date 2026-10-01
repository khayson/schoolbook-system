<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLevelRequest;
use App\Http\Requests\Api\V1\UpdateLevelRequest;
use App\Http\Resources\LevelResource;
use App\Models\Level;
use App\Support\ResolvesSlug;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LevelController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Level::class);

        $search = $request->string('search')->trim()->toString();

        $query = Level::query()
            ->with('levelGroup')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return LevelResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreLevelRequest $request): LevelResource
    {
        $data = ResolvesSlug::forName($request->validated());
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $level = Level::query()->create($data);
        $level->load('levelGroup');

        return new LevelResource($level);
    }

    public function show(Level $level): LevelResource
    {
        $this->authorize('view', $level);

        $level->load('levelGroup');

        return new LevelResource($level);
    }

    public function update(UpdateLevelRequest $request, Level $level): LevelResource
    {
        $data = $request->validated();

        if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            $data = ResolvesSlug::forName($data);
        }

        $level->update($data);
        $level->load('levelGroup');

        return new LevelResource($level);
    }

    public function destroy(Level $level): LevelResource
    {
        $this->authorize('delete', $level);

        $level->delete();

        return new LevelResource($level);
    }
}
