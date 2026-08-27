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
     * Lists notifications addressed to admin/staff recipients.
     */
    public function index(ListAdminNotificationsRequest $request): JsonResponse
    {
        $per_page  = min($request->integer('per_page', 15), 100);
        $filters   = $request->only(['type', 'is_read']);
        $paginated = $this->notificationService->getAdminNotifications($filters, $per_page);

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
     * Returns the count of unread, non-archived platform notifications.
     */
    public function unreadCount(): JsonResponse
    {
        $count = $this->notificationService->getAdminUnreadCount();

        return response()->json([
            'data' => ['unread_count' => $count],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/read
     * Marks a single notification as read.
     */
    public function markAsRead(Notification $notification): JsonResponse
    {
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
     * Marks all non-archived platform notifications as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        $updated_count = $this->notificationService->markAdminAllAsRead();

        return response()->json([
            'data' => ['updated_count' => $updated_count],
        ]);
    }

    /**
     * PATCH /api/admin/notifications/{id}/archive
     * Archives a single notification.
     */
    public function archive(Notification $notification): JsonResponse
    {
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
     * Restores an archived notification back to the Active tab.
     */
    public function unarchive(Notification $notification): JsonResponse
    {
        $this->notificationService->unarchive($notification);

        return response()->json([
            'data' => [
                'id'          => $notification->id,
                'is_archived' => false,
            ],
        ]);
    }
}
