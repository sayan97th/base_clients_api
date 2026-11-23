<?php

namespace Tests\Unit\Models;

use App\Models\SeoPackageSubscription;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Billing calendar math of SeoPackageSubscription. Pure date logic, no
 * database (the app is booted only for date casts): the same anchor day
 * every month, clamped to shorter months.
 */
class SeoPackageSubscriptionScheduleTest extends TestCase
{
    public static function advanceProvider(): array
    {
        return [
            'regular month'               => ['2026-12-01', 1, '2027-01-01'],
            'mid month'                   => ['2026-11-15', 15, '2026-12-15'],
            '31st into a 30 day month'    => ['2026-03-31', 31, '2026-04-30'],
            '31st into February'          => ['2027-01-31', 31, '2027-02-28'],
            '31st into a leap February'   => ['2028-01-31', 31, '2028-02-29'],
            'back to the 31st after Feb'  => ['2027-02-28', 31, '2027-03-31'],
            '30th after a short February' => ['2027-02-28', 30, '2027-03-30'],
            'year rollover'               => ['2026-12-31', 31, '2027-01-31'],
        ];
    }

    #[DataProvider('advanceProvider')]
    public function test_advance_by_one_month_keeps_the_anchor_day(string $from, int $anchor_day, string $expected): void
    {
        $this->assertSame($expected, SeoPackageSubscription::advanceByOneMonth(Carbon::parse($from), $anchor_day)->toDateString());
    }

    public function test_period_end_is_the_day_before_the_next_charge(): void
    {
        $subscription = new SeoPackageSubscription(['billing_anchor_day' => 31]);

        $this->assertSame('2027-02-27', $subscription->periodEndFor(Carbon::parse('2027-01-31'))->toDateString());

        $subscription = new SeoPackageSubscription(['billing_anchor_day' => 1]);
        $this->assertSame('2026-12-31', $subscription->periodEndFor(Carbon::parse('2026-12-01'))->toDateString());
    }

    public function test_anchor_day_falls_back_to_the_next_billing_date(): void
    {
        $subscription = new SeoPackageSubscription(['next_billing_date' => '2026-12-17']);

        $this->assertSame(17, $subscription->anchorDay());
    }

    public function test_pricing_and_payment_status_helpers(): void
    {
        $subscription = new SeoPackageSubscription([
            'pricing_type'   => SeoPackageSubscription::PRICING_CUSTOM,
            'payment_status' => SeoPackageSubscription::PAYMENT_STATUS_PAST_DUE,
            'status'         => 'active',
        ]);

        $this->assertTrue($subscription->isCustomPricing());
        $this->assertTrue($subscription->isPastDue());
        $this->assertTrue($subscription->isActive());
        $this->assertSame('SEO Services', $subscription->invoiceItemLabel());
    }
}
