<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return response()->json([
                'message' => 'Idempotency-Key header is required.',
                'code' => 'idempotency_key_required',
                'errors' => [
                    'Idempotency-Key' => ['The Idempotency-Key header is required.'],
                ],
            ], 422);
        }

        $key = trim($key);
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'code' => 'unauthenticated',
            ], 401);
        }

        $route = strtoupper($request->method()).' /'.$request->path();
        $hash = hash('sha256', $this->canonicalPayload($request));

        $existing = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            return $this->replayOrReject($existing, $hash);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'key' => $key,
                'user_id' => $user->id,
                'route' => $route,
                'request_hash' => $hash,
                'response_status' => 0,
                'response_body' => null,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = IdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('key', $key)
                ->firstOrFail();

            return $this->replayOrReject($existing, $hash);
        }

        $response = $next($request);

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
        ]);

        return $response;
    }

    private function replayOrReject(IdempotencyKey $existing, string $hash): Response
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            return response()->json([
                'message' => 'Idempotency key was reused with a different payload.',
                'code' => 'idempotency_key_mismatch',
                'errors' => [
                    'Idempotency-Key' => ['This key was already used with a different request body.'],
                ],
            ], 422);
        }

        if (! $existing->isComplete()) {
            return response()->json([
                'message' => 'A request with this Idempotency-Key is already in progress.',
                'code' => 'idempotency_key_in_progress',
            ], 409);
        }

        return response($existing->response_body, $existing->response_status)
            ->header('Content-Type', 'application/json')
            ->header('Idempotency-Replayed', 'true');
    }

    private function canonicalPayload(Request $request): string
    {
        $content = $request->getContent();

        if ($content !== '') {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->recursiveKeySort($decoded);

                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return $content;
        }

        $input = $request->all();
        $this->recursiveKeySort($input);

        return json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function recursiveKeySort(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->recursiveKeySort($value);
            }
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || str_contains($e->getMessage(), 'UNIQUE');
    }
}
