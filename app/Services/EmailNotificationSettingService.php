<?php

namespace App\Services;

use App\Models\EmailNotificationSetting;
use App\Models\User;

class EmailNotificationSettingService
{
    public static function resolveAdminRecipients(): array
    {
        $settings   = EmailNotificationSetting::first();
        $recipients = [];

        if (!$settings || $settings->notify_all_admins) {
            $users = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin', 'staff']))
                ->where('is_active', true)
                ->get();
        } else {
            $users = User::whereIn('id', $settings->enabled_user_ids ?? [])
                ->where('is_active', true)
                ->get();
        }

        foreach ($users as $user) {
            $recipients[] = ['name' => $user->first_name, 'email' => $user->email];
        }

        foreach ($settings->custom_emails ?? [] as $email) {
            $recipients[] = ['name' => '', 'email' => $email];
        }

        return $recipients;
    }
}
