<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated_data = $request->validated();

        $user = User::create([
            'first_name' => $validated_data['first_name'],
            'last_name' => $validated_data['last_name'],
            'email' => $validated_data['email'],
            'business_email' => $validated_data['business_email'] ?? null,
            'password' => $validated_data['password'],
        ]);

        $user->preference()->create();
        $user->billingAddress()->create();

        $token = auth()->login($user);

        return $this->respondWithToken($token, $user);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $token = auth()->attempt($credentials);

        if (!$token) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        return $this->respondWithToken($token, auth()->user());
    }

    public function me(): JsonResponse
    {
        $user = auth()->user();
        $user->load('roles');

        $globalRoles = $user->getGlobalRoles();

        $organizations = $user->organizations->map(function ($org) use ($user) {
            return [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'roles' => $user->getRolesForOrganization($org->id),
                'permissions' => $user->getAllPermissions($org->id),
            ];
        })->unique('id')->values();

        return response()->json([
            'user' => $user->makeHidden('roles'),
            'global_roles' => $globalRoles,
            'organizations' => $organizations,
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->logout();

        return response()->json([
            'message' => 'Successfully logged out.',
        ]);
    }

    public function refresh(): JsonResponse
    {
        $token = auth()->refresh();

        return $this->respondWithToken($token, auth()->user());
    }

    protected function respondWithToken(string $token, $user = null): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60,
            'user' => $user ? [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
            ] : null,
        ]);
    }
}
