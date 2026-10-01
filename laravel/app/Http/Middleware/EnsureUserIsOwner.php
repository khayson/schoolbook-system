<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== UserRole::Owner || ! $user->is_active) {
            return response()->json([
                'message' => 'Owner role required.',
                'code' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
