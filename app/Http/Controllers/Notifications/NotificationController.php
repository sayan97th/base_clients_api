<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\ListNotificationsRequest;
use App\Http\Requests\Notification\SnoozeNotificationRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $user = auth()->user();
        $filters = $request->only(['type', 'is_read']);
        $per_page = $request->integer('per_page', 15);

        $notifications = $this->notificationService->getNotifications($user, $filters, $per_page);

        return response()->json([
            'notifications' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $user = auth()->user();
        $count = $this->notificationService->getUnreadCount($user);

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $this->notificationService->markAsRead($notification);

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead(): JsonResponse
    {
        $user = auth()->user();
        $count = $this->notificationService->markAllAsRead($user);

        return response()->json([
            'message' => "All notifications marked as read.",
            'updated_count' => $count,
        ]);
    }

    public function archive(Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $this->notificationService->archive($notification);

        return response()->json([
            'message' => 'Notification archived.',
            'notification' => $notification->fresh(),
        ]);
    }

    public function snooze(SnoozeNotificationRequest $request, Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $snoozed_until = new \DateTime($request->validated('snoozed_until'));
        $this->notificationService->snooze($notification, $snoozed_until);

        return response()->json([
            'message' => 'Notification snoozed.',
            'notification' => $notification->fresh(),
        ]);
    }
}
