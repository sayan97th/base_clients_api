<?php

namespace App\Http\Controllers\Client\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\CreateNotificationRequest;
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

    public function store(CreateNotificationRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user         = auth()->user();
        $validated    = $request->validated();
        $notification = $this->notificationService->createNotification(
            $user,
            $validated['type'],
            $validated['message'],
            [
                'preview_text' => $validated['preview_text'] ?? null,
                'link'         => $validated['link'] ?? null,
            ]
        );

        return response()->json(['data' => $notification], 201);
    }

    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $user    = auth()->user();
        $filters = $request->only(['type', 'is_read']);

        if ($request->has('per_page')) {
            $per_page  = $request->integer('per_page', 15);
            $paginated = $this->notificationService->getNotifications($user, $filters, $per_page);

            return response()->json([
                'data'       => $paginated->items(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                ],
            ]);
        }

        $notifications = $this->notificationService->getAllNotifications($user, $filters);

        return response()->json(['data' => $notifications]);
    }

    public function unreadCount(): JsonResponse
    {
        $user  = auth()->user();
        $count = $this->notificationService->getUnreadCount($user);

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
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

    public function markAllAsRead(): JsonResponse
    {
        $user          = auth()->user();
        $updated_count = $this->notificationService->markAllAsRead($user);

        return response()->json([
            'data' => ['updated_count' => $updated_count],
        ]);
    }

    public function archive(Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
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

    public function unarchive(Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
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

    public function snooze(SnoozeNotificationRequest $request, Notification $notification): JsonResponse
    {
        $user = auth()->user();

        if ($notification->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $snooze_until = $request->validated('snooze_until')
            ? new \DateTime($request->validated('snooze_until'))
            : (new \DateTime())->modify('+1 hour');

        $this->notificationService->snooze($notification, $snooze_until);

        $notification->refresh();

        return response()->json([
            'data' => [
                'id'           => $notification->id,
                'is_snoozed'   => true,
                'is_read'      => true,
                'snoozed_until' => $notification->snoozed_until?->toIso8601String(),
            ],
        ]);
    }
}
