<?php

namespace App\Http\Controllers\Admin\EmailNotification;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailNotification\UpdateEmailNotificationSettingRequest;
use App\Models\EmailNotificationSetting;
use Illuminate\Http\JsonResponse;

class EmailNotificationSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = EmailNotificationSetting::first();

        if (!$settings) {
            return response()->json([
                'notify_all_admins' => true,
                'enabled_user_ids'  => [],
                'custom_emails'     => [],
            ]);
        }

        return response()->json([
            'notify_all_admins' => $settings->notify_all_admins,
            'enabled_user_ids'  => $settings->enabled_user_ids ?? [],
            'custom_emails'     => $settings->custom_emails ?? [],
        ]);
    }

    public function update(UpdateEmailNotificationSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        EmailNotificationSetting::updateOrCreate(
            ['id' => 1],
            [
                'notify_all_admins' => $validated['notify_all_admins'],
                'enabled_user_ids'  => $validated['enabled_user_ids'],
                'custom_emails'     => $validated['custom_emails'],
            ]
        );

        return response()->json([
            'message' => 'Email notification settings updated successfully',
        ]);
    }
}
