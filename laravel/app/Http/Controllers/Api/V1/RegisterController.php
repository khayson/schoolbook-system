<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function store(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $user = $action->execute($request->validated());

        return response()->json([
            'message' => 'Registration successful. Your account is pending admin approval.',
            'user' => new UserResource($user),
        ], 201);
    }
}
