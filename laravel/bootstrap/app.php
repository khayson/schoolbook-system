<?php

use App\Exceptions\ApiDomainException;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\RecordResponseTime;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request: X-Response-Time header, slow ones logged (config app.slow_request_ms).
        $middleware->prepend(RecordResponseTime::class);
        $middleware->alias([
            'owner' => EnsureUserIsOwner::class,
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ApiDomainException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($e->toEnvelope(), $e->status());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'unauthenticated',
                    'errors' => (object) [],
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'validation_failed',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Resource not found.',
                    'code' => 'not_found',
                ], 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e->getStatusCode() === 401) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'unauthenticated',
                ], 401);
            }

            if ($e->getStatusCode() === 403) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Forbidden.',
                    'code' => 'forbidden',
                ], 403);
            }

            return null;
        });
    })->create();
