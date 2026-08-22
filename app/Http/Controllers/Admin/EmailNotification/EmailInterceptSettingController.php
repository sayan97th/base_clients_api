<?php

namespace App\Http\Controllers\Admin\EmailNotification;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailNotification\UpdateEmailInterceptSettingRequest;
use App\Models\EmailInterceptLog;
use App\Models\EmailInterceptSetting;
use App\Services\EmailInterceptService;
use Illuminate\Http\JsonResponse;

class EmailInterceptSettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = EmailInterceptSetting::first();

        if (! $settings) {
            return response()->json([
                'intercept_admin_emails'  => false,
                'intercept_client_emails' => false,
                'recipient_emails'        => [],
            ]);
        }

        return response()->json([
            'intercept_admin_emails'  => $settings->intercept_admin_emails,
            'intercept_client_emails' => $settings->intercept_client_emails,
            'recipient_emails'        => $settings->recipient_emails ?? [],
        ]);
    }

    public function update(UpdateEmailInterceptSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        EmailInterceptSetting::updateOrCreate(
            ['id' => 1],
            [
                'intercept_admin_emails'  => $validated['intercept_admin_emails'],
                'intercept_client_emails' => $validated['intercept_client_emails'],
                'recipient_emails'        => $validated['recipient_emails'],
            ]
        );

        EmailInterceptService::invalidateCache();

        return response()->json([
            'message' => 'Email interceptor settings updated successfully',
        ]);
    }

    public function logs(): JsonResponse
    {
        $logs = EmailInterceptLog::query()
            ->orderByDesc('intercepted_at')
            ->limit(25)
            ->get([
                'mailable_class',
                'audience',
                'original_recipient_email',
                'subject',
                'copied_to_emails',
                'intercepted_at',
            ]);

        return response()->json(['logs' => $logs]);
    }
}
