<?php

namespace App\Services;

use App\Models\EmailInterceptLog;
use App\Models\EmailInterceptSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class EmailInterceptService
{
    public const AUDIENCE_ADMIN  = 'admin';
    public const AUDIENCE_CLIENT = 'client';

    private const ADMIN_ROLES  = ['super_admin', 'admin', 'staff'];
    private const CACHE_KEY    = 'email_intercept_settings';
    private const CACHE_TTL    = 60;

    /**
     * Determines who the destination address belongs to. A recipient found
     * among the admin-side roles is classified as "admin", otherwise the
     * address is treated as "client" — this also covers not-yet-created
     * invitees (team/staff/client invitations), which skew client-facing.
     */
    public static function resolveAudience(string $email): string
    {
        $is_admin_user = User::where('email', $email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::ADMIN_ROLES))
            ->exists();

        return $is_admin_user ? self::AUDIENCE_ADMIN : self::AUDIENCE_CLIENT;
    }

    /**
     * Returns the list of addresses that should receive a BCC copy of an
     * email sent to $to_email, or an empty array when interception is off
     * for that audience. The configured recipients never BCC themselves.
     */
    public static function getBccRecipients(string $to_email): array
    {
        $settings = self::cachedSettings();

        // No settings row, or no destination addresses configured at all: never
        // intercept, regardless of what the toggles say. This is the safety net
        // for a stale/corrupted toggle (e.g. enabled with an empty list left
        // over from removing every recipient) so a misconfigured setting can
        // never silently start mirroring mail with nowhere to send the copy.
        if (! $settings || empty($settings->recipient_emails)) {
            return [];
        }

        $audience = self::resolveAudience($to_email);

        $intercept_enabled = $audience === self::AUDIENCE_ADMIN
            ? $settings->intercept_admin_emails
            : $settings->intercept_client_emails;

        if (! $intercept_enabled) {
            return [];
        }

        return array_values(array_filter(
            $settings->recipient_emails,
            fn (string $recipient) => strcasecmp($recipient, $to_email) !== 0
        ));
    }

    public static function logIntercept(
        string $mailable_class,
        string $to_email,
        ?string $subject,
        array $copied_to_emails,
    ): void {
        EmailInterceptLog::create([
            'mailable_class'            => $mailable_class,
            'audience'                  => self::resolveAudience($to_email),
            'original_recipient_email'  => $to_email,
            'subject'                   => $subject,
            'copied_to_emails'          => $copied_to_emails,
            'intercepted_at'            => now(),
        ]);

        // Amortize the prune cost instead of running it on every single send.
        if (random_int(1, 20) === 1) {
            EmailInterceptLog::pruneOldEntries();
        }
    }

    public static function invalidateCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function cachedSettings(): ?EmailInterceptSetting
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => EmailInterceptSetting::first(),
        );
    }
}
