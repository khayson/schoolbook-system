<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReferenceBook;
use App\Models\ReferenceEdition;
use App\Services\Reference\ReferenceSnapshot;
use Illuminate\Http\JsonResponse;

class ReferenceEditionController extends Controller
{
    /**
     * The live edition (data: null before the first publish). snapshot_etag lets the app
     * tell whether its offline copy is current without downloading it.
     */
    public function active(ReferenceSnapshot $snapshot): JsonResponse
    {
        $this->authorize('viewAny', ReferenceBook::class);

        $edition = ReferenceEdition::active();

        return response()->json(['data' => $edition === null ? null : [
            'id' => $edition->id,
            'label' => $edition->label,
            'source_url' => $edition->source_url,
            'published_at' => $edition->published_at?->toDateString(),
            'activated_at' => $edition->activated_at?->toIso8601String(),
            'books_count' => ReferenceBook::query()->approved()->count(),
            'snapshot_etag' => $snapshot->etag(),
        ]]);
    }
}
