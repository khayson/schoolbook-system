<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server time of every request (3.4 review: the emulator's 25-35 s logins). Sends
 * `X-Response-Time: <ms>ms` (from LARAVEL_START, so framework boot is included) and logs
 * any request slower than config('app.slow_request_ms') with route, duration and peak
 * memory. Comparing the header with what the client measured separates server time from
 * network time.
 */
class RecordResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        $response = $next($request);

        $ms = (int) round((microtime(true) - $start) * 1000);
        $response->headers->set('X-Response-Time', $ms.'ms');

        if ($ms > (int) config('app.slow_request_ms', 1000)) {
            Log::warning('Slow request', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->uri(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $ms,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return $response;
    }
}
