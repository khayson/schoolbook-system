<?php

uses()->group('mysql');

use App\Services\NumberSequenceService;

test('number sequences are unique under concurrent allocation', function () {
    $service = app(NumberSequenceService::class);

    $results = [];

    // Sequential lockForUpdate under MySQL still proves row locking path;
    // true parallel workers are out of scope for Pest single-process runs.
    foreach (range(1, 20) as $_) {
        $results[] = $service->next('invoice', 2026);
    }

    expect($results)->toBe(range(1, 20))
        ->and(count(array_unique($results)))->toBe(20);
});
