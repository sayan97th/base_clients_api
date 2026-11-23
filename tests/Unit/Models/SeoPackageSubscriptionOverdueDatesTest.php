<?php

namespace Tests\Unit\Models;

use App\Models\SeoPackageSubscription;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SeoPackageSubscription::overdueBillingDates(): the billing dates that
 * already passed without a payment. The schedule only moves forward once a
 * period is paid, so every date from next_billing_date up to yesterday is
 * owed. Pure date math, no database.
 */
class SeoPackageSubscriptionOverdueDatesTest extends TestCase
{
    private function subscription(array $attributes): SeoPackageSubscription
    {
        return new SeoPackageSubscription(array_merge([
            'billing_anchor_day' => null,
            'ends_at'            => null,
        ], $attributes));
    }

    private function datesOf(SeoPackageSubscription $subscription, string $today, int $limit = 36): array
    {
        return array_map(
            fn (Carbon $date) => $date->toDateString(),
            $subscription->overdueBillingDates(Carbon::parse($today), $limit)
        );
    }

    public function test_nothing_is_owed_without_a_billing_date(): void
    {
        $this->assertSame([], $this->datesOf($this->subscription(['next_billing_date' => null]), '2026-12-01'));
    }

    public function test_nothing_is_owed_when_the_billing_date_is_today_or_later(): void
    {
        $this->assertSame([], $this->datesOf($this->subscription(['next_billing_date' => '2026-12-01']), '2026-12-01'));
        $this->assertSame([], $this->datesOf($this->subscription(['next_billing_date' => '2027-01-01']), '2026-12-01'));
    }

    public function test_every_passed_billing_date_is_owed_but_today_is_not(): void
    {
        $subscription = $this->subscription(['next_billing_date' => '2026-09-01', 'billing_anchor_day' => 1]);

        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $this->datesOf($subscription, '2026-12-01'));
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01', '2026-12-01'], $this->datesOf($subscription, '2026-12-02'));
    }

    public function test_the_time_of_day_never_changes_the_result(): void
    {
        $subscription = $this->subscription(['next_billing_date' => '2026-11-15', 'billing_anchor_day' => 15]);

        $this->assertSame(['2026-11-15'], $this->datesOf($subscription, '2026-12-01 00:00:01'));
        $this->assertSame(['2026-11-15'], $this->datesOf($subscription, '2026-12-01 23:59:59'));
    }

    public function test_an_anchor_on_the_31st_follows_short_months(): void
    {
        $subscription = $this->subscription(['next_billing_date' => '2026-12-31', 'billing_anchor_day' => 31]);

        $this->assertSame(
            ['2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31'],
            $this->datesOf($subscription, '2027-04-01')
        );
    }

    public function test_the_anchor_falls_back_to_the_billing_date_day(): void
    {
        $subscription = $this->subscription(['next_billing_date' => '2026-10-20']);

        $this->assertSame(['2026-10-20', '2026-11-20'], $this->datesOf($subscription, '2026-12-01'));
    }

    public function test_dates_after_the_end_date_are_never_owed(): void
    {
        $subscription = $this->subscription([
            'next_billing_date'  => '2026-09-01',
            'billing_anchor_day' => 1,
            'ends_at'            => '2026-10-15',
        ]);

        $this->assertSame(['2026-09-01', '2026-10-01'], $this->datesOf($subscription, '2026-12-01'));
    }

    public function test_a_billing_date_on_the_end_date_is_still_owed(): void
    {
        $subscription = $this->subscription([
            'next_billing_date'  => '2026-10-01',
            'billing_anchor_day' => 1,
            'ends_at'            => '2026-11-01',
        ]);

        $this->assertSame(['2026-10-01', '2026-11-01'], $this->datesOf($subscription, '2026-12-01'));
    }

    public function test_a_forgotten_subscription_is_capped(): void
    {
        $subscription = $this->subscription(['next_billing_date' => '2016-01-01', 'billing_anchor_day' => 1]);

        $this->assertCount(36, $this->datesOf($subscription, '2026-12-01'));
        $this->assertCount(5, $this->datesOf($subscription, '2026-12-01', 5));
    }
}
