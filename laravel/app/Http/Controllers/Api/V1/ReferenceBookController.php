<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PaginatesApiLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListReferenceBooksRequest;
use App\Http\Resources\ReferenceBookResource;
use App\Models\ReferenceBook;
use App\Services\Reference\ReferenceBookSearch;
use App\Services\Reference\ReferenceSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ReferenceBookController extends Controller
{
    use PaginatesApiLists;

    public function index(ListReferenceBooksRequest $request, ReferenceBookSearch $search): AnonymousResourceCollection
    {
        $query = ReferenceBook::query()
            ->with(['level', 'subject', 'language', 'publisher'])
            ->withCount('products')
            ->withSum('products', 'stock_on_hand')
            ->where('status', $request->input('status', 'approved'));

        foreach (['level_id', 'subject_id', 'language_id', 'publisher_id', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->has('stocked')) {
            $request->boolean('stocked') ? $query->has('products') : $query->doesntHave('products');
        }

        $text = trim((string) $request->input('search', ''));
        $text === '' ? $query->orderBy('search_title')->orderBy('id') : $search->apply($query, $text);

        return ReferenceBookResource::collection($query->paginate($this->perPage($request)));
    }

    /**
     * The whole live list for offline search. gzip when the client accepts it; 304 when
     * the client's copy (If-None-Match) is current.
     */
    public function snapshot(Request $request, ReferenceSnapshot $snapshot): Response
    {
        $this->authorize('viewAny', ReferenceBook::class);

        $etag = $snapshot->etag();
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache', 'Vary' => 'Accept-Encoding'];

        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($etag, $sent, true)) {
            return response('', 304, $headers);
        }

        $json = $snapshot->json();
        $headers['Content-Type'] = 'application/json';

        if (str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip')) {
            return response((string) gzencode($json, 6), 200, $headers + ['Content-Encoding' => 'gzip']);
        }

        return response($json, 200, $headers);
    }
}
