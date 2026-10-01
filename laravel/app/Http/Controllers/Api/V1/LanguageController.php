<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLanguageRequest;
use App\Http\Requests\Api\V1\UpdateLanguageRequest;
use App\Http\Resources\LanguageResource;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LanguageController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Language::class);

        $search = $request->string('search')->trim()->toString();

        $query = Language::query()->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return LanguageResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreLanguageRequest $request): LanguageResource
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        $language = Language::query()->create($data);

        return new LanguageResource($language);
    }

    public function show(Language $language): LanguageResource
    {
        $this->authorize('view', $language);

        return new LanguageResource($language);
    }

    public function update(UpdateLanguageRequest $request, Language $language): LanguageResource
    {
        $language->update($request->validated());

        return new LanguageResource($language);
    }

    public function destroy(Language $language): LanguageResource
    {
        $this->authorize('delete', $language);

        $language->delete();

        return new LanguageResource($language);
    }
}
