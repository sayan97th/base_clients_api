<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Coupon dates are now calendar days in the promotion timezone and both days
 * are included: a coupon expiring on 2026-12-18 works until 23:59:59 on
 * December 18 (see Coupon::startsAt and Coupon::expiresAt).
 *
 * Before this change the admin form saved a date as midnight UTC, so a coupon
 * set to expire on December 18 actually stopped working at the very start of
 * that day (the evening of December 17 in US time). This migration keeps the
 * calendar day the admin picked and applies the new rule to it:
 *   starts_at  D 00:00:00 UTC  becomes  start of D in the promotion timezone
 *   expires_at D 00:00:00 UTC  becomes  end of D in the promotion timezone
 * Only values saved at exactly midnight UTC (date only values) are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $promotion_timezone = (string) config('app.promotion_timezone', 'UTC');

        DB::table('coupons')
            ->where(fn ($query) => $query->whereNotNull('starts_at')->orWhereNotNull('expires_at'))
            ->orderBy('id')
            ->each(function (object $coupon) use ($promotion_timezone) {
                $changes = [];

                if ($this->isUtcMidnight($coupon->starts_at)) {
                    $changes['starts_at'] = $this->toUtc(
                        Carbon::parse($coupon->starts_at, 'UTC')->format('Y-m-d'),
                        $promotion_timezone,
                        false,
                    );
                }

                if ($this->isUtcMidnight($coupon->expires_at)) {
                    $changes['expires_at'] = $this->toUtc(
                        Carbon::parse($coupon->expires_at, 'UTC')->format('Y-m-d'),
                        $promotion_timezone,
                        true,
                    );
                }

                if ($changes) {
                    DB::table('coupons')->where('id', $coupon->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        $promotion_timezone = (string) config('app.promotion_timezone', 'UTC');

        DB::table('coupons')
            ->where(fn ($query) => $query->whereNotNull('starts_at')->orWhereNotNull('expires_at'))
            ->orderBy('id')
            ->each(function (object $coupon) use ($promotion_timezone) {
                $changes = [];

                foreach (['starts_at' => '00:00:00', 'expires_at' => '23:59:59'] as $column => $local_time) {
                    if (! $coupon->{$column}) {
                        continue;
                    }

                    $local = Carbon::parse($coupon->{$column}, 'UTC')->setTimezone($promotion_timezone);

                    if ($local->format('H:i:s') === $local_time) {
                        $changes[$column] = $local->format('Y-m-d') . ' 00:00:00';
                    }
                }

                if ($changes) {
                    DB::table('coupons')->where('id', $coupon->id)->update($changes);
                }
            });
    }

    private function isUtcMidnight(?string $value): bool
    {
        return $value !== null && Carbon::parse($value, 'UTC')->format('H:i:s') === '00:00:00';
    }

    private function toUtc(string $day, string $timezone, bool $is_end_of_day): string
    {
        $moment = Carbon::createFromFormat('Y-m-d', $day, $timezone);
        $moment = $is_end_of_day ? $moment->endOfDay()->startOfSecond() : $moment->startOfDay();

        return $moment->utc()->format('Y-m-d H:i:s');
    }
};
