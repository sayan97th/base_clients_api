<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\Invoice;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Services\SeoSubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\Support\SeoSubscriptions\InteractsWithSeoSubscriptions;
use Tests\TestCase;

/**
 * Edge cases of the SEO subscription billing engine: dunning limits, card
 * fallback, Stripe idempotency, reminder windows, end dates and anchors.
 */
class SeoSubscriptionBillingEdgeCasesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-01 15:00:00');

        $this->seedAdminRoles();
        $this->seedInvoiceNumberCounter();
        $this->client = $this->makeUserWithRole('client', ['stripe_customer_id' => 'cus_client']);

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function decline(string $error_code = 'card_declined'): array
    {
        return ['success' => false, 'message' => 'The card was declined.', 'error_code' => $error_code];
    }

    // ── Dunning ─────────────────────────────────────────────────────────────

    public function test_retries_stop_after_the_last_scheduled_attempt(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->times(4)
            ->andReturn($this->decline('insufficient_funds')));

        // Attempt 1 on Dec 1, then retries 3, 5 and 7 days after each failure.
        foreach (['2026-12-01', '2026-12-04', '2026-12-09', '2026-12-16'] as $day) {
            Carbon::setTestNow("{$day} 15:00:00");
            $this->runBilling();
        }

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame(4, $charge->attempt_count);
        $this->assertNull($charge->next_retry_at);
        $this->assertSame('past_due', $subscription->refresh()->payment_status);

        // The client hears about the first and the final failure only.
        $this->assertSame(2, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED));

        // Nothing else happens later on.
        Carbon::setTestNow('2027-01-10 15:00:00');
        $this->runBilling();
        $this->assertSame(4, $charge->refresh()->attempt_count);
    }

    public function test_every_attempt_uses_its_own_stripe_idempotency_key(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $keys = [];

        $this->mockStripe(function (MockInterface $mock) use (&$keys) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->twice()
                ->withArgs(function (...$args) use (&$keys) {
                    $keys[] = $args[4];

                    return true;
                })
                ->andReturn($this->decline(), ['success' => true, 'payment_intent_id' => 'pi_ok']);
        });

        $this->runBilling();
        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->runBilling();

        $charge_id = SeoSubscriptionCharge::firstOrFail()->id;
        $this->assertSame([
            "seo-subscription-charge-{$charge_id}-attempt-1",
            "seo-subscription-charge-{$charge_id}-attempt-2",
        ], $keys);
    }

    public function test_a_client_without_a_card_is_not_retried_and_is_asked_to_pay(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('failed', $charge->status);
        $this->assertSame('no_payment_method', $charge->failure_code);
        $this->assertNull($charge->next_retry_at);
        $this->assertNotNull($charge->invoice_id, 'The client can still pay the invoice from the portal.');
        $this->assertSame('past_due', $subscription->refresh()->payment_status);
        $this->assertSame(1, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED));
    }

    public function test_manual_billing_failures_are_never_retried_automatically(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['auto_renew' => false]);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn($this->decline()));

        app(SeoSubscriptionBillingService::class)->chargeCurrentPeriod($subscription, SeoSubscriptionCharge::TRIGGER_MANUAL);

        $this->assertNull(SeoSubscriptionCharge::firstOrFail()->next_retry_at);
    }

    public function test_a_retry_reuses_the_period_invoice_even_after_a_price_change(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->twice()
            ->andReturn($this->decline(), ['success' => true, 'payment_intent_id' => 'pi_ok']));

        $this->runBilling();
        $subscription->update(['monthly_price' => 2500]);

        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->runBilling();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(2000.0, Invoice::firstOrFail()->total_amount);
    }

    // ── Card selection ──────────────────────────────────────────────────────

    public function test_an_expired_renewal_card_falls_back_to_a_valid_card(): void
    {
        $expired = $this->makeCardFor($this->client, ['stripe_payment_method_id' => 'pm_expired', 'expiry_year' => '2020', 'is_default' => false]);
        $this->makeCardFor($this->client, ['stripe_payment_method_id' => 'pm_valid', 'last_four' => '5555']);
        $this->makeSubscriptionFor($this->client, ['payment_profile_id' => $expired->id]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->withArgs(fn (...$args) => $args[2] === 'pm_valid')
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_ok']));

        $this->runBilling();

        $this->assertSame('Visa ending in 5555', SeoSubscriptionCharge::firstOrFail()->card_label);
    }

    // ── Invoice and Stripe details ──────────────────────────────────────────

    public function test_the_invoice_line_uses_the_custom_label_and_period(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client, [
            'invoice_item_label' => 'SEO Retainer',
            'plan_name'          => 'Custom Growth Plus',
        ]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->withArgs(fn (...$args) => $args[3]['seo_subscription_id'] !== ''
                && $args[3]['billing_period'] === '2026-12-01'
                && $args[5] === 'SEO subscription, December 2026 (Custom Growth Plus)')
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_ok']));

        $this->runBilling();

        $line_item = Invoice::with('lineItems')->firstOrFail()->lineItems->first();
        $this->assertSame('December - SEO Retainer', $line_item->item_name);
        $this->assertStringContainsString('Custom Growth Plus', $line_item->description);
        $this->assertStringContainsString('Dec 1, 2026 to Dec 31, 2026', $line_item->description);
    }

    // ── End dates and statuses ──────────────────────────────────────────────

    public function test_no_charge_happens_after_the_end_date(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01', 'ends_at' => '2026-12-10']);
        $subscription->update(['next_billing_date' => '2026-12-01']);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_last']));

        $this->runBilling(); // December is inside the end date.

        Carbon::setTestNow('2027-01-01 15:00:00');
        $this->runBilling(); // January is after it: expire, never charge.

        $this->assertSame(1, SeoSubscriptionCharge::count());
        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertFalse($subscription->auto_renew);

        $result = app(SeoSubscriptionBillingService::class)->chargeCurrentPeriod($subscription, SeoSubscriptionCharge::TRIGGER_MANUAL);
        $this->assertFalse($result['success']);
    }

    public function test_cancelled_and_expired_subscriptions_are_never_charged(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client, ['status' => 'cancelled']);
        $this->makeSubscriptionFor($this->client, ['status' => 'expired']);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling();

        $this->assertSame(0, SeoSubscriptionCharge::count());
    }

    public function test_reminders_only_mode_never_charges(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling(['--reminders-only' => true]);

        $this->assertSame(0, SeoSubscriptionCharge::count());
    }

    public function test_a_price_below_the_stripe_minimum_is_refused(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['monthly_price' => 0.25]);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $result = app(SeoSubscriptionBillingService::class)->chargeCurrentPeriod($subscription, SeoSubscriptionCharge::TRIGGER_MANUAL);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status_code']);
        $this->assertSame(0, Invoice::count());
    }

    // ── Billing anchor ──────────────────────────────────────────────────────

    public function test_a_subscription_anchored_on_the_31st_bills_on_month_ends(): void
    {
        Carbon::setTestNow('2027-01-31 15:00:00');
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-31', 'billing_anchor_day' => 31]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->times(3)
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_ok']));

        $this->runBilling();
        $this->assertSame('2027-02-28', $subscription->refresh()->next_billing_date->toDateString());

        Carbon::setTestNow('2027-02-28 15:00:00');
        $this->runBilling();
        $this->assertSame('2027-03-31', $subscription->refresh()->next_billing_date->toDateString());

        Carbon::setTestNow('2027-03-31 15:00:00');
        $this->runBilling();
        $this->assertSame('2027-04-30', $subscription->refresh()->next_billing_date->toDateString());
    }

    // ── Renewal reminders ───────────────────────────────────────────────────

    public function test_reminders_respect_each_subscription_setting(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        // Due Dec 4 with a 3 day reminder: inside the window, sent.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-04', 'reminder_days_before' => 3]);
        // Reminder turned off.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-04', 'reminder_days_before' => null]);
        // Manual billing: no "we will charge you" email.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-04', 'auto_renew' => false]);
        // Past due: the client already got a payment failed email.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-04', 'payment_status' => 'past_due']);
        // Too early: due in 6 days with a 3 day reminder.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-07', 'reminder_days_before' => 3]);
        // Ends before the renewal.
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-04', 'ends_at' => '2026-12-03']);

        $this->runBilling(['--reminders-only' => true]);

        $this->assertSame(1, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER));
    }

    public function test_a_reminder_is_sent_again_for_the_next_period(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-03']);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling(['--reminders-only' => true]);
        $this->assertSame('2026-12-03', $subscription->refresh()->last_reminder_sent_for->toDateString());

        $subscription->update(['next_billing_date' => '2027-01-03']);
        Carbon::setTestNow('2026-12-31 15:00:00');
        $this->runBilling(['--reminders-only' => true]);

        $this->assertSame(2, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER));
    }

    // ── Schedule preview ────────────────────────────────────────────────────

    public function test_the_upcoming_schedule_stops_at_the_end_date(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-01', 'ends_at' => '2027-01-15']);

        $schedule = app(SeoSubscriptionBillingService::class)->upcomingSchedule($subscription);

        $this->assertSame(['2026-12-01', '2027-01-01'], array_column($schedule, 'date'));
        $this->assertSame('December 2026', $schedule[0]['period_label']);
        $this->assertSame([], app(SeoSubscriptionBillingService::class)->upcomingSchedule(
            $this->makeSubscriptionFor($this->client, ['status' => 'cancelled'])
        ));
    }

    public function test_display_names_fall_back_when_there_is_no_plan_name(): void
    {
        $this->makePackage();

        $this->assertSame('Growth', $this->makeSubscriptionFor($this->client, ['plan_name' => null, 'seo_package_id' => 'growth'])->displayPlanName());
        $this->assertSame('Custom SEO Package', $this->makeSubscriptionFor($this->client, ['plan_name' => null])->displayPlanName());
        $this->assertSame(SeoPackageSubscription::DEFAULT_INVOICE_ITEM_LABEL, $this->makeSubscriptionFor($this->client)->invoiceItemLabel());
    }
}
