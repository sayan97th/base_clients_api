<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Client\StoreClientRequest;
use App\Http\Resources\UserWithRolesResource;
use App\Mail\ClientWelcomeEmail;
use App\Mail\ClientPlatformWelcomeEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AdminClientController extends Controller
{
    /**
     * POST /api/admin/clients/{user_id}/resend-welcome-email
     */
    public function resendWelcomeEmail(int $user_id): JsonResponse
    {
        $user = User::with(['roles:id,name,display_name', 'organization'])->find($user_id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $is_client = $user->roles->contains('name', 'client')
            && $user->roles->whereIn('name', ['super_admin', 'admin', 'staff'])->isEmpty();

        if (! $is_client) {
            return response()->json(['message' => 'This action is only available for client accounts.'], 422);
        }

        if ($user->password_reset_at !== null) {
            return response()->json([
                'message' => 'This client has already reset their password. The welcome email cannot be resent.',
            ], 422);
        }

        try {
            $token     = Password::createToken($user);
            $email     = urlencode($user->email);
            $reset_url = rtrim(config('app.frontend_url'), '/') . "/reset-password/{$token}?email={$email}";

            Mail::to($user->email)->send(new ClientWelcomeEmail(
                user: $user,
                reset_url: $reset_url,
                temporary_password: null,
            ));

            return response()->json([
                'message' => 'Welcome email has been resent successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /api/admin/clients/bulk-welcome-email
     */
    public function bulkSendWelcomeEmail(Request $request): JsonResponse
    {
        $send_to_all = $request->boolean('send_to_all', false);
        $user_ids    = $request->input('user_ids', []);

        if (! $send_to_all && empty($user_ids)) {
            return response()->json(['message' => 'No clients selected.'], 422);
        }

        $query = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']));

        if (! $send_to_all) {
            $query->whereIn('id', $user_ids);
        }

        $users = $query->get();

        $sent    = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($users as $user) {
            if ($user->password_reset_at !== null) {
                $skipped++;
                continue;
            }

            try {
                $token     = Password::createToken($user);
                $email     = urlencode($user->email);
                $reset_url = rtrim(config('app.frontend_url'), '/') . "/reset-password/{$token}?email={$email}";

                Mail::to($user->email)->send(new ClientPlatformWelcomeEmail(
                    user: $user,
                    reset_url: $reset_url,
                ));

                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'message' => "Bulk welcome email operation completed.",
            'sent'    => $sent,
            'skipped' => $skipped,
            'failed'  => $failed,
        ]);
    }

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
