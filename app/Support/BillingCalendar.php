<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Decides what "today" means for SEO subscription billing.
 *
 * The app stores and runs in UTC, but admins pick billing dates in their own
 * local calendar. In the evening (US time) UTC is already on the next day, so
 * comparing a local "today" against the UTC date wrongly rejected it as a past
 * date. Every billing date check goes through this class instead:
 *
 *   - Requests from the admin forms send the browser timezone, so "today" is
 *     the admin's own calendar day.
 *   - The scheduler and anything without a timezone use the billing timezone,
 *     the same one the billing run is scheduled in.
 */
class BillingCalendar
{
    public const DEFAULT_TIMEZONE = 'America/Denver';

    public static function timezone(): string
    {
        $timezone = (string) config('app.billing_timezone', self::DEFAULT_TIMEZONE);

        return self::isValidTimezone($timezone) ? $timezone : self::DEFAULT_TIMEZONE;
    }

    /**
     * Start of the current calendar day, as a plain date (no timezone shift),
     * so it compares directly with the date only columns.
     */
    public static function today(?string $timezone = null): Carbon
    {
        $zone = $timezone && self::isValidTimezone($timezone) ? $timezone : self::timezone();

        return Carbon::parse(now($zone)->toDateString())->startOfDay();
    }

    public static function todayString(?string $timezone = null): string
    {
        return self::today($timezone)->toDateString();
    }

    /**
     * True when the date is earlier than today in the given (or billing)
     * timezone. Today itself is never in the past.
     */
    public static function isPast(string $date, ?string $timezone = null): bool
    {
        return Carbon::parse($date)->startOfDay()->lt(self::today($timezone));
    }

    public static function isToday(string $date, ?string $timezone = null): bool
    {
        return Carbon::parse($date)->startOfDay()->equalTo(self::today($timezone));
    }

    public static function isValidTimezone(?string $timezone): bool
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true);
    }
}
