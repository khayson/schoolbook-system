<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait PaginatesApiLists
{
    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 25);

        return min(max($perPage, 1), 100);
    }
}
