<?php

namespace App\Http\Controllers\Admin\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Client\StoreClientRequest;
use App\Http\Resources\UserWithRolesResource;
use App\Jobs\SendWelcomeEmailInBatchJob;
use App\Mail\ClientWelcomeEmail;
use App\Mail\ClientPlatformWelcomeEmail;
use App\Models\BulkEmailBatch;
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

            $user->update(['welcome_email_sent_at' => now()]);

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
     * GET /api/admin/clients/pending-count
     */
    public function getPendingClientsCount(): JsonResponse
    {
        $count = User::whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']))
            ->whereNull('password_reset_at')
            ->count();

        return response()->json(['pending_count' => $count]);
    }

    /**
     * POST /api/admin/clients/bulk-welcome-email
     * Creates a batch and dispatches individual queue jobs for each recipient.
     * Returns immediately with a batch_id so the frontend can poll for progress.
     */
    public function startBulkWelcomeEmail(Request $request): JsonResponse
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
        } else {
            $query->whereNull('password_reset_at');
        }

        $ids = $query->pluck('id')->all();

        if (empty($ids)) {
            return response()->json(['message' => 'No eligible clients found.'], 422);
        }

        $batch = BulkEmailBatch::create([
            'status'      => 'processing',
            'total_count' => count($ids),
        ]);

        foreach ($ids as $user_id) {
            SendWelcomeEmailInBatchJob::dispatch($user_id, $batch->id);
        }

        return response()->json([
            'batch_id'    => $batch->id,
            'total_count' => $batch->total_count,
            'status'      => $batch->status,
        ], 202);
    }

    /**
     * GET /api/admin/clients/bulk-email-batch/{batch_id}
     * Returns the current progress of a bulk email batch.
     */
    public function getBulkEmailBatchStatus(int $batch_id): JsonResponse
    {
        $batch = BulkEmailBatch::find($batch_id);

        if (! $batch) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        return response()->json([
            'batch_id'      => $batch->id,
            'status'        => $batch->status,
            'total_count'   => $batch->total_count,
            'sent_count'    => $batch->sent_count,
            'skipped_count' => $batch->skipped_count,
            'failed_count'  => $batch->failed_count,
            'processed_count' => $batch->processed_count,
            'completed_at'  => $batch->completed_at?->toIso8601String(),
            'stopped_at'    => $batch->stopped_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/admin/clients/bulk-email-batch/{batch_id}/stop
     * Signals all pending jobs for this batch to abort before sending.
     */
    public function stopBulkEmailBatch(int $batch_id): JsonResponse
    {
        $batch = BulkEmailBatch::find($batch_id);

        if (! $batch) {
            return response()->json(['message' => 'Batch not found.'], 404);
        }

        if ($batch->status !== 'processing') {
            return response()->json(['message' => 'This batch is no longer active.'], 422);
        }

        $batch->markStopped();

        return response()->json([
            'message'       => 'Bulk email send has been stopped.',
            'batch_id'      => $batch->id,
            'status'        => $batch->status,
            'sent_count'    => $batch->sent_count,
            'skipped_count' => $batch->skipped_count,
            'failed_count'  => $batch->failed_count,
        ]);
    }

    /**
     * POST /api/admin/clients/send-test-welcome-email
     */
    public function sendTestWelcomeEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $preview_email = $request->input('email');

        $dummy_user                        = new User();
        $dummy_user->first_name            = 'Test';
        $dummy_user->last_name             = 'Client';
        $dummy_user->email                 = $preview_email;
        $dummy_user->welcome_email_sent_at = null;

        $reset_url = rtrim(config('app.frontend_url'), '/') . '/reset-password/preview-token?email=' . urlencode($preview_email);

        try {
            Mail::to($preview_email)->send(new ClientPlatformWelcomeEmail(
                user: $dummy_user,
                reset_url: $reset_url,
            ));

            return response()->json([
                'message' => "Test welcome email sent to {$preview_email}.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send test email. Please try again.',
            ], 500);
        }
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

                $user->update(['welcome_email_sent_at' => now()]);
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
