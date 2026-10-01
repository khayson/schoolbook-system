<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubjectRequest;
use App\Http\Requests\Api\V1\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Support\ResolvesSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubjectController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subject::class);

        $search = $request->string('search')->trim()->toString();

        $query = Subject::query()->orderBy('name');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return SubjectResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = ResolvesSlug::forName($request->validated());
        $data['is_active'] = $data['is_active'] ?? true;

        $subject = Subject::query()->create($data);

        return (new SubjectResource($subject))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Subject $subject): SubjectResource
    {
        $this->authorize('view', $subject);

        return new SubjectResource($subject);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $data = $request->validated();

        if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
            $data = ResolvesSlug::forName($data);
        }

        $subject->update($data);

        return new SubjectResource($subject);
    }

    public function destroy(Subject $subject): SubjectResource
    {
        $this->authorize('delete', $subject);

        $subject->delete();

        return new SubjectResource($subject);
    }
}
