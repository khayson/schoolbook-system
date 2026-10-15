<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active || $user->role !== UserRole::Owner) {
            return response()->json([
                'message' => 'This account is not allowed to access the API.',
                'code' => 'forbidden',
            ], 403);
        }

        $device = $request->string('device_name')->toString() ?: 'mobile';
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json([
                'message' => 'No account was found for this email.',
                'code' => 'account_not_found',
                'data' => ['status' => 'not_found'],
            ], 404);
        }

        $status = $user->is_active ? 'active' : 'pending';
        $message = $user->is_active
            ? 'Your account is active. You can sign in now.'
            : 'Your account is still under review. An admin needs to activate it — usually within 1–2 business days.';

        return response()->json([
            'data' => [
                'status' => $status,
                'message' => $message,
                'name' => $user->name,
                'registered_at' => $user->registered_at?->toIso8601String(),
            ],
        ]);
    }
}
