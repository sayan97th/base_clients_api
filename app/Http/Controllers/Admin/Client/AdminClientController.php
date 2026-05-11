<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Client\StoreClientRequest;
use App\Http\Resources\UserWithRolesResource;
use App\Mail\ClientWelcomeEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AdminClientController extends Controller
{
    /**
     * POST /api/admin/clients
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        try {
            $plain_password = $request->input('password') ?? Str::random(16);

            $user = User::create([
                'first_name'        => $request->input('first_name'),
                'last_name'         => $request->input('last_name'),
                'email'             => $request->input('email'),
                'password'          => $plain_password,
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);

            $user->assignRole('client');

            if ($request->boolean('send_welcome_email')) {
                $token     = Password::createToken($user);
                $email     = urlencode($user->email);
                $reset_url = rtrim(config('app.frontend_url'), '/') . "/reset-password/{$token}?email={$email}";

                $temporary_password = $request->filled('password')
                    ? $request->input('password')
                    : null;

                Mail::to($user->email)->send(new ClientWelcomeEmail(
                    user: $user,
                    reset_url: $reset_url,
                    temporary_password: $temporary_password,
                ));
            }

            $user->load(['roles:id,name,display_name', 'organization']);

            return response()->json([
                'message' => 'Client account created successfully.',
                'user'    => new UserWithRolesResource($user),
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }
}
