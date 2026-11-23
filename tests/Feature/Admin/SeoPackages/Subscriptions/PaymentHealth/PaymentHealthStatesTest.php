<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions\PaymentHealth;

use App\Models\SeoSubscriptionCharge;
use App\Services\SeoSubscriptionPaymentHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeoSubscriptions\PaymentHealthTestCase;

/**
 * Every payment health state an SEO subscription can be in, how far behind
 * it is, and how severe it is. The state is derived on every read, so these
 * tests build the situation and evaluate it.
 */
class PaymentHealthStatesTest extends PaymentHealthTestCase
{
    // ── Healthy and inactive ────────────────────────────────────────────────

    public function test_a_paid_up_subscription_with_a_card_is_healthy(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-01']);

        $health = $this->health($subscription);

        $this->assertSame('healthy', $health['state']);
        $this->assertSame('ok', $health['severity']);
        $this->assertSame(0, $health['overdue_periods']);
        $this->assertEquals(0.0, $health['overdue_amount']);
        $this->assertNull($health['oldest_due_date']);
        $this->assertFalse($health['suggest_cancellation']);
        $this->assertTrue($health['has_usable_card']);
        $this->assertNull($health['card_issue']);
        $this->assertNull($health['failure']);
        $this->assertNull($health['open_invoice']);
    }

    public function test_a_renewal_due_today_that_was_not_attempted_yet_is_not_late(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01']);

        $health = $this->health($subscription);

        $this->assertSame('healthy', $health['state']);
        $this->assertSame(0, $health['overdue_periods']);
    }

    public function test_cancelled_and_expired_subscriptions_are_inactive_even_when_they_owe_money(): void
    {
        foreach (['cancelled', 'expired'] as $status) {
            $subscription = $this->makeSubscriptionFor($this->client, [
                'status'            => $status,
                'payment_status'    => 'past_due',
                'next_billing_date' => '2026-09-01',
            ]);

            $health = $this->health($subscription);

            $this->assertSame('inactive', $health['state'], $status);
            $this->assertSame('ok', $health['severity'], $status);
            $this->assertSame(0, $health['overdue_periods'], $status);
        }
    }

    // ── Declined renewals ───────────────────────────────────────────────────

    public function test_a_declined_auto_renewal_is_retrying_with_the_reason_and_the_open_invoice(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $this->runBilling();

        $health = $this->health($subscription);

        $this->assertSame('retrying', $health['state']);
        $this->assertSame('warning', $health['severity']);
        $this->assertSame(1, $health['overdue_periods'], 'Declined today is owed even though the date is not past.');
        $this->assertEquals(2000.0, $health['overdue_amount']);
        $this->assertSame('2026-12-01', $health['oldest_due_date']);
        $this->assertSame(0, $health['days_overdue']);

        $failure = $health['failure'];
        $this->assertSame('insufficient_funds', $failure['code']);
        $this->assertSame('Your card has insufficient funds.', $failure['message']);
        $this->assertSame('Your bank declined the payment due to insufficient funds.', $failure['client_reason']);
        $this->assertSame('December 2026', $failure['period_label']);
        $this->assertSame(1, $failure['attempt_count']);
        $this->assertSame(4, $failure['max_attempts']);
        $this->assertTrue($failure['is_retryable']);
        $this->assertSame('2026-12-04', Carbon::parse($failure['next_retry_at'])->toDateString());

        $this->assertSame('unpaid', $health['open_invoice']['status']);
        $this->assertNotEmpty($health['open_invoice']['unique_id']);
    }

    public function test_retries_running_out_turns_the_renewal_into_a_critical_payment_failure(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        foreach (['2026-12-01', '2026-12-04', '2026-12-09', '2026-12-16'] as $day) {
            Carbon::setTestNow("{$day} 15:00:00");
            $this->runBilling();
        }

        $health = $this->health($subscription);

        $this->assertSame('payment_failed', $health['state']);
        $this->assertSame('critical', $health['severity']);
        $this->assertSame(4, $health['failure']['attempt_count']);
        $this->assertNull($health['failure']['next_retry_at']);
        $this->assertSame(15, $health['days_overdue']);
    }

    public function test_a_decline_a_retry_can_not_fix_fails_immediately(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge('expired_card', 'Your card has expired.');

        $this->runBilling();

        $health = $this->health($subscription);

        $this->assertSame('payment_failed', $health['state']);
        $this->assertFalse($health['failure']['is_retryable']);
        $this->assertSame('The card on file has expired.', $health['failure']['client_reason']);
    }

    public function test_a_renewal_without_any_card_fails_and_reports_the_missing_card(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe();

        $this->runBilling();

        $health = $this->health($subscription);

        $this->assertSame('payment_failed', $health['state']);
        $this->assertSame('critical', $health['severity']);
        $this->assertSame('missing', $health['card_issue']);
        $this->assertFalse($health['has_usable_card']);
        $this->assertSame('no_payment_method', $health['failure']['code']);
        $this->assertSame('There is no card on file for your account.', $health['failure']['client_reason']);
    }

    public function test_a_retrying_subscription_two_periods_behind_is_critical(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'payment_status'    => 'past_due',
            'next_billing_date' => '2026-11-01',
        ]);
        $this->makeFailedCharge($subscription, ['period_start' => '2026-11-01', 'next_retry_at' => now()->addDay()]);

        $health = $this->health($subscription);

        $this->assertSame('retrying', $health['state']);
        $this->assertSame(1, $health['overdue_periods'], 'November passed, December 1 is due today.');

        Carbon::setTestNow('2026-12-02 15:00:00');
        $health = $this->health($subscription);

        $this->assertSame(2, $health['overdue_periods']);
        $this->assertTrue($health['suggest_cancellation']);
        $this->assertSame('critical', $health['severity']);
    }

    public function test_an_unpaid_period_the_schedule_already_moved_past_is_still_owed(): void
    {
        $this->makeCardFor($this->client);
        // An admin moved the billing date to January after November failed.
        $subscription = $this->makeSubscriptionFor($this->client, [
            'payment_status'    => 'past_due',
            'next_billing_date' => '2027-01-01',
        ]);
        $this->makeFailedCharge($subscription, ['period_start' => '2026-11-01', 'amount' => 1500, 'next_retry_at' => null]);

        $health = $this->health($subscription);

        $this->assertSame('payment_failed', $health['state']);
        $this->assertSame(1, $health['overdue_periods']);
        $this->assertEquals(1500.0, $health['overdue_amount'], 'The amount of the failed charge, not the current price.');
        $this->assertSame('2026-11-01', $health['oldest_due_date']);
        $this->assertSame(30, $health['days_overdue']);
    }

    public function test_an_invoice_paid_since_the_decline_is_not_reported_as_open(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        SeoSubscriptionCharge::firstOrFail()->invoice->update(['status' => 'paid']);

        $this->assertNull($this->health($subscription)['open_invoice']);
    }

    // ── Payments pending ────────────────────────────────────────────────────

    public function test_an_active_subscription_months_behind_is_payment_pending(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-10-01',
        ]);

        $health = $this->health($subscription);

        $this->assertSame('payment_pending', $health['state']);
        $this->assertSame('critical', $health['severity']);
        $this->assertSame(2, $health['overdue_periods'], 'October and November passed, December 1 is due today.');
        $this->assertEquals(4000.0, $health['overdue_amount']);
        $this->assertSame('2026-10-01', $health['oldest_due_date']);
        $this->assertSame(61, $health['days_overdue']);
        $this->assertTrue($health['suggest_cancellation']);
        $this->assertNull($health['failure']);
    }

    public function test_one_pending_period_is_a_warning_without_a_cancellation_suggestion(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-11-15',
        ]);

        $health = $this->health($subscription);

        $this->assertSame('payment_pending', $health['state']);
        $this->assertSame('warning', $health['severity']);
        $this->assertSame(1, $health['overdue_periods']);
        $this->assertSame(16, $health['days_overdue']);
        $this->assertFalse($health['suggest_cancellation']);
    }

    public function test_pending_periods_use_the_monthly_price_when_no_charge_exists(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'monthly_price'     => 1499.5,
            'next_billing_date' => '2026-09-01',
        ]);

        $health = $this->health($subscription);

        $this->assertSame(3, $health['overdue_periods']);
        $this->assertEquals(4498.5, $health['overdue_amount']);
    }

    public function test_billing_dates_after_the_end_date_are_never_owed(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-10-01',
            'ends_at'           => '2026-10-15',
        ]);

        $health = $this->health($subscription);

        $this->assertSame(1, $health['overdue_periods']);
        $this->assertEquals(2000.0, $health['overdue_amount']);
    }

    // ── Cards ───────────────────────────────────────────────────────────────

    public function test_an_auto_renewal_with_no_card_warns_before_the_renewal_fails(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $health = $this->health($subscription);

        $this->assertSame('no_payment_method', $health['state']);
        $this->assertSame('warning', $health['severity']);
        $this->assertSame('missing', $health['card_issue']);
        $this->assertSame(0, $health['overdue_periods']);
    }

    public function test_only_expired_cards_count_as_an_expired_card_issue(): void
    {
        $this->makeCardFor($this->client, ['expiry_month' => '01', 'expiry_year' => '2025']);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $health = $this->health($subscription);

        $this->assertSame('no_payment_method', $health['state']);
        $this->assertSame('expired', $health['card_issue']);
    }

    public function test_one_valid_card_among_expired_ones_is_enough(): void
    {
        $this->makeCardFor($this->client, ['expiry_month' => '01', 'expiry_year' => '2025']);
        $this->makeCardFor($this->client, ['expiry_month' => '12', 'expiry_year' => '2026', 'is_default' => false]);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $this->assertSame('healthy', $this->health($subscription)['state']);
    }

    public function test_manual_billing_without_a_card_is_not_a_problem_until_a_date_passes(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-12-20',
        ]);

        $health = $this->health($subscription);

        $this->assertSame('healthy', $health['state']);
        $this->assertSame('missing', $health['card_issue'], 'The card issue is still reported for the admin.');
    }

    public function test_cards_of_another_client_are_never_counted(): void
    {
        $other_client = $this->makeUserWithRole('client');
        $this->makeCardFor($other_client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $this->assertSame('no_payment_method', $this->health($subscription)['state']);
    }

    public function test_priming_cards_loads_every_client_in_one_query(): void
    {
        $clients = collect(range(1, 5))->map(fn () => $this->makeUserWithRole('client'));
        $clients->each(fn ($client) => $this->makeCardFor($client));

        $service = app(SeoSubscriptionPaymentHealthService::class);

        DB::enableQueryLog();
        $service->primeCardStatus($clients->pluck('id'));
        $clients->each(fn ($client) => $this->assertTrue($service->hasUsableCard($client->id)));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    // ── Client view ─────────────────────────────────────────────────────────

    public function test_the_client_view_hides_processor_messages_and_internal_ids(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $client_health = app(SeoSubscriptionPaymentHealthService::class)->evaluateForClient($subscription->fresh());

        $this->assertSame('retrying', $client_health['state']);
        $this->assertSame('Your bank declined the payment due to insufficient funds.', $client_health['failure']['reason']);
        $this->assertArrayNotHasKey('message', $client_health['failure']);
        $this->assertArrayNotHasKey('code', $client_health['failure']);
        $this->assertArrayNotHasKey('charge_id', $client_health['failure']);
        $this->assertArrayNotHasKey('id', $client_health['open_invoice']);
        $this->assertArrayNotHasKey('suggest_cancellation', $client_health);
        $this->assertSame(['unique_id', 'invoice_number'], array_keys($client_health['open_invoice']));
    }
}
