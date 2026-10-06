<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Schools\AddDirectorySchoolAsCustomer;
use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddDirectorySchoolRequest;
use App\Http\Requests\Api\V1\ListDirectorySchoolsRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\DirectorySchoolResource;
use App\Models\DirectorySchool;
use App\Services\Schools\DirectorySchoolSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** School directory (docs/school-directory.md): search, add a school as a customer. */
class DirectorySchoolController extends Controller
{
    use PaginatesApiLists;

    public function index(ListDirectorySchoolsRequest $request): AnonymousResourceCollection
    {
        $query = DirectorySchool::query()->listed();
        if ($request->filled('region')) {
            $query->where('region', $request->string('region')->toString());
        }
        if ($request->filled('district')) {
            $query->where('district', $request->string('district')->toString());
        }
        if ($request->has('added')) {
            $request->boolean('added') ? $query->whereNotNull('customer_id') : $query->whereNull('customer_id');
        }
        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            DirectorySchoolSearch::apply($query, $search);
        }

        return DirectorySchoolResource::collection($query->orderBy('search_name')->orderBy('id')->paginate($this->perPage($request)))
            ->additional(['meta' => ['attribution' => DirectorySchool::ATTRIBUTION]]);
    }

    public function addAsCustomer(AddDirectorySchoolRequest $request, DirectorySchool $directorySchool, AddDirectorySchoolAsCustomer $add): JsonResponse
    {
        $data = $request->validated();
        $customer = $add->execute(
            $directorySchool,
            array_intersect_key($data, array_flip(['contact_person', 'phone', 'email', 'credit_limit'])),
            isset($data['link_customer_id']) ? (int) $data['link_customer_id'] : null,
        );

        return (new CustomerResource($customer->fresh()))->response()->setStatusCode(isset($data['link_customer_id']) ? 200 : 201);
    }
}
