<?php

namespace App\Http\Controllers\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\ImpersonationService;
use App\Support\NotificationLinkValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Handles the "View Invoice" style link inside a client-side notification email
 * being opened by someone who currently holds an admin/staff session. The frontend
 * route guard cannot decide who a notification belongs to on its own (it only sees
 * cookies), so it forwards the notification id here and this controller resolves
 * the actual ownership and impersonation eligibility against the database.
 */
class NotificationRedirectController extends Controller
{
    public function __construct(
        protected ImpersonationService $impersonation_service
    ) {}

    /**
     * GET /api/admin/notifications/{notification}/redirect-context
     *
     * Read-only. Tells the frontend whether this notification belongs to a client
     * account and whether the current admin/staff caller is allowed to impersonate
     * that client, without performing any state-changing action.
     */
    public function context(Notification $notification): JsonResponse
    {
        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        $target = $notification->user()->with('roles:id,name,display_name')->first();

        if (! $target || ! $this->impersonation_service->isClientOnly($target)) {
            return response()->json([
                'message' => 'This notification does not belong to a client account.',
            ], 422);
        }

        $redirect_path = NotificationLinkValidator::sanitizeRelativePath($notification->link) ?? '/';

        // A client-owned notification should never carry an "/admin" link, but this
        // endpoint's entire job is to gate access to the admin portal, so refuse to
        // hand back a path that would route an impersonated client session there.
        if (str_starts_with($redirect_path, '/admin')) {
            $redirect_path = '/';
        }

        $can_impersonate = $admin->id !== $target->id
            && $target->is_active
            && $admin->hasRole(['super_admin', 'admin'])
            && $admin->hasPermission('users.impersonate');

        return response()->json([
            'data' => [
                'notification_id'   => $notification->id,
                'belongs_to_client' => true,
                'redirect_path'     => $redirect_path,
                'can_impersonate'   => $can_impersonate,
                'target_user'       => [
                    'id'         => $target->id,
                    'first_name' => $target->first_name,
                    'last_name'  => $target->last_name,
                    'email'      => $target->email,
                    'is_active'  => $target->is_active,
                ],
            ],
        ]);
    }

    /**
     * POST /api/admin/notifications/{notification}/impersonate
     *
     * A purpose-built, tightly scoped impersonation entry point. Unlike the general
     * admin-panel impersonation flow (ImpersonationController::impersonate), this
     * action may NEVER be used to impersonate a staff-side account, regardless of
     * the caller's own role, because it exists specifically to view a client
     * notification and must not become a side door into another admin's session.
     */
    public function impersonate(Notification $notification): JsonResponse
    {
        /** @var \App\Models\User $admin */
        $admin = auth()->user();

        // Route-level middleware already restricts this action to super_admin/admin,
        // but the check is repeated here so the controller stays safe even if the
        // route definition ever changes.
        if (! $admin->hasRole(['super_admin', 'admin'])) {
            return response()->json([
                'message' => 'Only administrators can use the impersonation feature.',
            ], 403);
        }

        if (! $admin->hasPermission('users.impersonate')) {
            return response()->json([
                'message' => 'You have insufficient permissions to use the impersonation feature.',
            ], 403);
        }

        $target = $notification->user()->with(['roles:id,name,display_name', 'organization'])->first();

        if (! $target) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($target->id === $admin->id) {
            return response()->json([
                'message' => 'You cannot impersonate your own account.',
            ], 422);
        }

        if (! $this->impersonation_service->isClientOnly($target)) {
            return response()->json([
                'message' => 'This action can only be used to impersonate client accounts.',
            ], 403);
        }

        if (! $target->is_active) {
            return response()->json([
                'message' => 'This account is currently disabled and cannot be impersonated.',
            ], 403);
        }

        Log::info('Admin started impersonation from a notification redirect.', [
            'admin_id'        => $admin->id,
            'target_id'       => $target->id,
            'notification_id' => $notification->id,
        ]);

        return response()->json(
            $this->impersonation_service->issue($admin, $target, origin: 'notification_redirect')
        );
    }
}
