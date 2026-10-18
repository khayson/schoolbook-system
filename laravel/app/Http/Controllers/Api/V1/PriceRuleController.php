<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePriceRuleRequest;
use App\Http\Requests\Api\V1\UpdatePriceRuleRequest;
use App\Http\Resources\PriceRuleResource;
use App\Models\PriceRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceRuleController extends Controller
{
    use PaginatesApiLists;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PriceRule::class);

        $search = $request->string('search')->trim()->toString();

        $query = PriceRule::query()->orderByDesc('priority')->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return PriceRuleResource::collection(
            $query->paginate($this->perPage($request)),
        );
    }

    public function store(StorePriceRuleRequest $request): PriceRuleResource
    {
        $data = $request->validated();
        $data['priority'] = $data['priority'] ?? 0;
        $data['stackable'] = $data['stackable'] ?? false;
        $data['floor_policy'] = $data['floor_policy'] ?? 'warn';
        $data['is_active'] = $data['is_active'] ?? true;

        $rule = PriceRule::query()->create($data);

        return new PriceRuleResource($rule);
    }

    public function show(PriceRule $priceRule): PriceRuleResource
    {
        $this->authorize('view', $priceRule);

        return new PriceRuleResource($priceRule);
    }

    public function update(UpdatePriceRuleRequest $request, PriceRule $priceRule): PriceRuleResource
    {
        $priceRule->update($request->validated());

        return new PriceRuleResource($priceRule->fresh());
    }

    public function destroy(PriceRule $priceRule): PriceRuleResource
    {
        $this->authorize('delete', $priceRule);

        $priceRule->delete();

        return new PriceRuleResource($priceRule);
    }
}
