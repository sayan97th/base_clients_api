<?php

namespace App\Http\Controllers;

use App\Http\Requests\Notification\UpdateNotificationPreferenceRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function show(): JsonResponse
    {
        $user = auth()->user();
        $preferences = $this->notificationService->getOrCreatePreferences($user);

        return response()->json([
            'preferences' => $preferences,
        ]);
    }

    public function update(UpdateNotificationPreferenceRequest $request): JsonResponse
    {
        $user = auth()->user();
        $preferences = $this->notificationService->updatePreferences($user, $request->validated());

        return response()->json([
            'message' => 'Notification preferences updated.',
            'preferences' => $preferences,
        ]);
    }
}
