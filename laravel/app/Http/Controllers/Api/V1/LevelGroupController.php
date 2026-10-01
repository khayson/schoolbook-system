<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\LevelGroupResource;
use App\Models\LevelGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LevelGroupController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LevelGroup::class);

        $search = $request->string('search')->trim()->toString();

        $query = LevelGroup::query()->orderBy('sort_order')->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return LevelGroupResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }
}
