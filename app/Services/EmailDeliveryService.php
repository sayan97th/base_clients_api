<?php

namespace App\Services;

use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Models\Notification;
use App\Models\UserEmailPreference;
use App\Support\Email\EmailTypeCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * Central delivery policy for every outgoing email. Decides, per recipient,
 * whether a given email type may be delivered, based on two layers:
 *
 *   1. The global switch for the email type (Email Types tab). Turning a
 *      type off stops it for everyone.
 *   2. The recipient's personal preferences (User Preferences tab): either
 *      every non mandatory email is muted, or only specific types are.
 *
 * Mandatory types and exempt classes (test emails, interceptor copies) are
 * always delivered. The policy is enforced from the MessageSending listener,
 * so no code path that sends mail can skip it.
 */
class EmailDeliveryService
{
    public const REASON_EMAIL_TYPE_DISABLED  = 'email_type_disabled';
    public const REASON_RECIPIENT_MUTED_ALL  = 'recipient_muted_all';
    public const REASON_RECIPIENT_OPTED_OUT  = 'recipient_opted_out';

    private const TYPE_SETTINGS_CACHE_KEY    = 'email_delivery_type_settings';
    private const PREFERENCES_CACHE_KEY      = 'email_delivery_user_preferences';
    private const CACHE_TTL                  = 60;

    /**
     * Returns the outgoing email's class, whether it was sent as a Mailable
     * or as a mail channel Notification.
     */
    public static function resolveMailClass(array $message_data): ?string
    {
        return $message_data['__laravel_mailable']
            ?? $message_data['__laravel_notification']
            ?? null;
    }

    public static function isExempt(array $message_data): bool
    {
        return EmailTypeCatalog::isExemptClass(self::resolveMailClass($message_data));
    }

    /**
     * Resolves the catalog email type for the data Laravel passes along with
     * MessageSending. A Mailable's public properties are part of that data,
     * which is how the generic NotificationEmail exposes its portal type.
     */
    public static function resolveEmailType(array $message_data): ?string
    {
        $notification      = $message_data['notification'] ?? null;
        $notification_type = $notification instanceof Notification ? $notification->type : null;

        return EmailTypeCatalog::resolveKey(self::resolveMailClass($message_data), $notification_type);
    }

    /**
     * Returns why $recipient_email must not receive $email_type, or null when
     * delivery is allowed. Unregistered types are allowed so a missing catalog
     * entry never silently drops mail; the test suite guards that gap instead.
     */
    public static function getBlockReason(?string $email_type, string $recipient_email): ?string
    {
        if ($email_type === null) {
            return null;
        }

        $definition = EmailTypeCatalog::find($email_type);

        if (! $definition || $definition['is_mandatory']) {
            return null;
        }

        if (! self::isEmailTypeEnabled($email_type)) {
            return self::REASON_EMAIL_TYPE_DISABLED;
        }

        $preference = self::cachedPreferences()[strtolower($recipient_email)] ?? null;

        if (! $preference) {
            return null;
        }

        if ($preference['mute_all_emails']) {
            return self::REASON_RECIPIENT_MUTED_ALL;
        }

        if (in_array($email_type, $preference['disabled_email_types'], true)) {
            return self::REASON_RECIPIENT_OPTED_OUT;
        }

        return null;
    }

    public static function isDeliveryAllowed(?string $email_type, string $recipient_email): bool
    {
        return self::getBlockReason($email_type, $recipient_email) === null;
    }

    /**
     * Email types are enabled unless an admin explicitly turned them off, so
     * a fresh install with no rows keeps sending everything as before.
     */
    public static function isEmailTypeEnabled(string $email_type): bool
    {
        return self::cachedTypeSettings()[$email_type] ?? true;
    }

    /**
     * Shapes catalog entries for the admin API, attaching whether each type
     * is currently enabled. Mandatory types always report as enabled.
     */
    public static function presentEmailTypes(array $email_types): array
    {
        return array_map(
            fn (array $email_type) => [
                'key'          => $email_type['key'],
                'label'        => $email_type['label'],
                'description'  => $email_type['description'],
                'audience'     => $email_type['audience'],
                'category'     => $email_type['category'],
                'is_mandatory' => $email_type['is_mandatory'],
                'is_enabled'   => $email_type['is_mandatory'] || self::isEmailTypeEnabled($email_type['key']),
            ],
            array_values($email_types),
        );
    }

    public static function logSuppression(
        string $mailable_class,
        ?string $email_type,
        string $recipient_email,
        ?string $subject,
        string $reason,
    ): void {
        EmailSuppressionLog::create([
            'mailable_class'  => $mailable_class,
            'email_type'      => $email_type,
            'recipient_email' => $recipient_email,
            'subject'         => $subject,
            'reason'          => $reason,
            'suppressed_at'   => now(),
        ]);

        // Amortize the prune cost instead of running it on every suppressed send.
        if (random_int(1, 20) === 1) {
            EmailSuppressionLog::pruneOldEntries();
        }
    }

    public static function invalidateCache(): void
    {
        Cache::forget(self::TYPE_SETTINGS_CACHE_KEY);
        Cache::forget(self::PREFERENCES_CACHE_KEY);
    }

    /**
     * @return array<string, bool> email type => is enabled
     */
    private static function cachedTypeSettings(): array
    {
        return Cache::remember(
            self::TYPE_SETTINGS_CACHE_KEY,
            self::CACHE_TTL,
            fn () => EmailTypeSetting::pluck('is_enabled', 'email_type')
                ->map(fn ($is_enabled) => (bool) $is_enabled)
                ->all(),
        );
    }

    /**
     * Only users who actually restricted something are cached, keyed by
     * lowercase email, so the lookup on every send is a single array access.
     *
     * @return array<string, array{mute_all_emails: bool, disabled_email_types: array}>
     */
    private static function cachedPreferences(): array
    {
        return Cache::remember(
            self::PREFERENCES_CACHE_KEY,
            self::CACHE_TTL,
            fn () => UserEmailPreference::with('user:id,email')
                ->get()
                ->filter(fn (UserEmailPreference $preference) => $preference->user
                    && ($preference->mute_all_emails || ! empty($preference->disabled_email_types)))
                ->mapWithKeys(fn (UserEmailPreference $preference) => [
                    strtolower($preference->user->email) => [
                        'mute_all_emails'      => $preference->mute_all_emails,
                        'disabled_email_types' => $preference->disabled_email_types ?? [],
                    ],
                ])
                ->all(),
        );
    }
}
