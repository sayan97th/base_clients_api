<?php

namespace App\Http\Controllers\Admin\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\ListAdminNotificationsRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * GET /api/admin/notifications
     * Lists notifications addressed to the authenticated admin/staff user only.
     */
    public function index(ListAdminNotificationsRequest $request): JsonResponse
    {
        $admin     = $request->user();
        $per_page  = min($request->integer('per_page', 15), 100);
        $filters   = $request->only(['type', 'is_read']);
        $paginated = $this->notificationService->getAdminNotifications($admin, $filters, $per_page);

        $data = collect($paginated->items())->map(fn (Notification $notification) => [
            'id'            => $notification->id,
            'user_id'       => $notification->user_id,
            'type'          => $notification->type,
            'message'       => $notification->message,
            'preview_text'  => $notification->preview_text,
            'link'          => $notification->link,
            'resource_type' => $notification->resource_type,
            'resource_id'   => $notification->resource_id,
            'metadata'      => $notification->metadata,
            'is_read'       => $notification->is_read,
            'is_archived'   => $notification->is_archived,
            'date'          => $notification->date,
            'relative_time' => $notification->relative_time,
            'created_at'    => $notification->created_at,
            'updated_at'    => $notification->updated_at,
            'user'          => $notification->user ? [
                'id'         => $notification->user->id,
                'first_name' => $notification->user->first_name,
                'last_name'  => $notification->user->last_name,
                'email'      => $notification->user->email,
            ] : null,
        ]);

        return response()->json([
            'data'         => $data,
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
        ]);
    }

    /**
     * GET /api/admin/notifications/unread-count
     * Returns the count of unread, non-archived notifications for the authenticated admin/staff user.
     */
    public function unreadCount(): JsonResponse
    {
        $count = $this->notificationService->getAdminUnreadCount(auth()->user());

        return response()->json([
            'data' => ['unread_count' => $count],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/read
     * Marks a single notification as read. Restricted to the owning admin so one admin
     * cannot mutate another admin's notification by guessing its id.
     */
    public function markAsRead(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->notificationService->markAsRead($notification);

        return response()->json([
            'data' => [
                'id'      => $notification->id,
                'is_read' => true,
            ],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/read-all
     * Marks all non-archived notifications for the authenticated admin/staff user as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        $updated_count = $this->notificationService->markAdminAllAsRead(auth()->user());

        return response()->json([
            'data' => ['updated_count' => $updated_count],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/archive
     * Archives a single notification. Restricted to the owning admin, see markAsRead().
     */
    public function archive(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->notificationService->archive($notification);

        return response()->json([
            'data' => [
                'id'          => $notification->id,
                'is_archived' => true,
            ],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/unarchive
     * Restores an archived notification back to the Active tab. Restricted to the owning
     * admin, see markAsRead().
     */
    public function unarchive(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->notificationService->unarchive($notification);

        return response()->json([
            'data' => [
                'id'          => $notification->id,
                'is_archived' => false,
            ],
        ]);
    }
}
