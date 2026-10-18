<?php

namespace App\Http\Controllers\Admin\EmailNotification;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailNotification\UpdateEmailTypeSettingsRequest;
use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Services\EmailDeliveryService;
use App\Support\Email\EmailTypeCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmailTypeSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'categories'  => EmailTypeCatalog::categoryList(),
            'email_types' => EmailDeliveryService::presentEmailTypes(EmailTypeCatalog::all()),
        ]);
    }

    public function update(UpdateEmailTypeSettingsRequest $request): JsonResponse
    {
        $email_types = $request->validated('email_types');

        DB::transaction(function () use ($email_types) {
            foreach ($email_types as $email_type) {
                EmailTypeSetting::updateOrCreate(
                    ['email_type' => $email_type['key']],
                    ['is_enabled' => $email_type['is_enabled']],
                );
            }
        });

        EmailDeliveryService::invalidateCache();

        return response()->json([
            'message' => 'Email type settings updated successfully',
        ]);
    }

    public function suppressionLogs(): JsonResponse
    {
        $logs = EmailSuppressionLog::query()
            ->orderByDesc('suppressed_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get([
                'mailable_class',
                'email_type',
                'recipient_email',
                'subject',
                'reason',
                'suppressed_at',
            ]);

        return response()->json(['logs' => $logs]);
    }
}
