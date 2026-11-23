<?php

namespace Tests\Unit\Support;

use App\Support\BillingCalendar;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What "today" means for SEO subscription billing. The app runs in UTC, so in
 * the US evening UTC is already on the next calendar day; BillingCalendar
 * must answer with the admin's own day (or the billing timezone), never UTC.
 */
class BillingCalendarTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function eveningProvider(): array
    {
        // 01:30 UTC on Nov 23 is the evening of Nov 22 in the Americas.
        return [
            'Mountain time' => ['America/Denver', '2026-11-22'],
            'Eastern time'  => ['America/New_York', '2026-11-22'],
            'Pacific time'  => ['America/Los_Angeles', '2026-11-22'],
            'Colombia'      => ['America/Bogota', '2026-11-22'],
            'UTC itself'    => ['UTC', '2026-11-23'],
            'Madrid'        => ['Europe/Madrid', '2026-11-23'],
        ];
    }

    #[DataProvider('eveningProvider')]
    public function test_today_follows_the_given_timezone(string $timezone, string $expected): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        $this->assertSame($expected, BillingCalendar::todayString($timezone));
    }

    public function test_today_is_a_plain_date_at_midnight(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        $today = BillingCalendar::today('America/Denver');

        $this->assertSame('2026-11-22 00:00:00', $today->format('Y-m-d H:i:s'));
        // No timezone shift: it compares directly with the date only columns.
        $this->assertSame(config('app.timezone'), $today->getTimezone()->getName());
    }

    public function test_without_a_timezone_the_billing_timezone_is_used(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');
        config(['app.billing_timezone' => 'America/Denver']);

        $this->assertSame('America/Denver', BillingCalendar::timezone());
        $this->assertSame('2026-11-22', BillingCalendar::todayString());
        $this->assertSame('2026-11-22', BillingCalendar::todayString(null));
        $this->assertSame('2026-11-22', BillingCalendar::todayString(''));
    }

    public function test_an_invalid_timezone_falls_back_to_the_billing_timezone(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');
        config(['app.billing_timezone' => 'America/Denver']);

        $this->assertSame('2026-11-22', BillingCalendar::todayString('Mars/Olympus_Mons'));
        $this->assertSame('2026-11-22', BillingCalendar::todayString('not a timezone'));
    }

    public function test_an_invalid_billing_timezone_config_falls_back_to_the_default(): void
    {
        config(['app.billing_timezone' => 'Nowhere/Invalid']);

        $this->assertSame(BillingCalendar::DEFAULT_TIMEZONE, BillingCalendar::timezone());
    }

    public function test_today_is_never_in_the_past(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        $this->assertFalse(BillingCalendar::isPast('2026-11-22', 'America/Denver'));
        $this->assertFalse(BillingCalendar::isPast('2026-11-23', 'America/Denver'));
        $this->assertTrue(BillingCalendar::isPast('2026-11-21', 'America/Denver'));
    }

    public function test_the_same_date_is_past_in_utc_but_not_for_the_admin(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        // The original bug: UTC says Nov 22 already passed.
        $this->assertTrue(BillingCalendar::isPast('2026-11-22', 'UTC'));
        $this->assertFalse(BillingCalendar::isPast('2026-11-22', 'America/Denver'));
    }

    public function test_time_parts_of_a_date_are_ignored(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        $this->assertFalse(BillingCalendar::isPast('2026-11-22 00:00:00', 'America/Denver'));
        $this->assertTrue(BillingCalendar::isToday('2026-11-22 23:59:59', 'America/Denver'));
    }

    public function test_is_today_matches_only_the_current_local_day(): void
    {
        Carbon::setTestNow('2026-11-23 01:30:00');

        $this->assertTrue(BillingCalendar::isToday('2026-11-22', 'America/Denver'));
        $this->assertFalse(BillingCalendar::isToday('2026-11-23', 'America/Denver'));
        $this->assertTrue(BillingCalendar::isToday('2026-11-23', 'UTC'));
    }

    public function test_it_validates_timezone_names(): void
    {
        $this->assertTrue(BillingCalendar::isValidTimezone('America/Denver'));
        $this->assertTrue(BillingCalendar::isValidTimezone('UTC'));
        $this->assertFalse(BillingCalendar::isValidTimezone(null));
        $this->assertFalse(BillingCalendar::isValidTimezone(''));
        $this->assertFalse(BillingCalendar::isValidTimezone('Denver'));
    }
}
