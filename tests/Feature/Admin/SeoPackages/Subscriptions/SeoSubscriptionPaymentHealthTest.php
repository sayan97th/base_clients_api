<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Jobs\SendAdminSeoSubscriptionPaymentMethodAlertJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Services\SeoSubscriptionBillingService;
use App\Services\SeoSubscriptionPaymentHealthService;
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
 * Payment health of SEO subscriptions: failed auto renewals, payments
 * pending for months, clients without a card, and what admins and clients
 * see and receive in each case.
 */
class SeoSubscriptionPaymentHealthTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;
    use InteractsWithSeoSubscriptions;

    private const ADMIN_URL = '/api/admin/seo-packages/subscriptions';

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-01 15:00:00');

        $this->seedAdminRoles();
        $this->seedInvoiceNumberCounter();

        $this->admin  = $this->makeUserWithRole('admin');
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

    private function health(SeoPackageSubscription $subscription): array
    {
        return app(SeoSubscriptionPaymentHealthService::class)->evaluate($subscription->fresh());
    }

    private function declineEveryCharge(string $error_code = 'insufficient_funds'): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->andReturn(['success' => false, 'message' => 'Your card has insufficient funds.', 'error_code' => $error_code]));
    }

    // ── States ──────────────────────────────────────────────────────────────

    public function test_a_paid_up_subscription_with_a_card_is_healthy(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-01']);

        $health = $this->health($subscription);

        $this->assertSame('healthy', $health['state']);
        $this->assertSame('ok', $health['severity']);
        $this->assertSame(0, $health['overdue_periods']);
        $this->assertTrue($health['has_usable_card']);
    }

    public function test_declined_auto_renewal_is_retrying_with_the_reason(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $this->runBilling();

        $health = $this->health($subscription);

        $this->assertSame('retrying', $health['state']);
        $this->assertSame(1, $health['overdue_periods']);
        $this->assertEquals(2000.0, $health['overdue_amount']);
        $this->assertSame('insufficient_funds', $health['failure']['code']);
        $this->assertSame('Your card has insufficient funds.', $health['failure']['message']);
        $this->assertNotNull($health['failure']['next_retry_at']);
        $this->assertNotNull($health['open_invoice']);
    }

    public function test_renewal_without_any_card_fails_for_good_and_notifies_everyone(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe();

        $this->runBilling();

        $health = $this->health($subscription);

        $this->assertSame('payment_failed', $health['state']);
        $this->assertSame('critical', $health['severity']);
        $this->assertSame('missing', $health['card_issue']);
        $this->assertSame('no_payment_method', $health['failure']['code']);
        $this->assertNull($health['failure']['next_retry_at']);
        $this->assertSame(1, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED));
    }

    public function test_active_subscription_months_behind_is_payment_pending_and_suggests_cancelling(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-10-01',
        ]);

        $health = $this->health($subscription);

        $this->assertSame('payment_pending', $health['state']);
        $this->assertSame('critical', $health['severity']);
        // October and November passed, December 1 is due today.
        $this->assertSame(2, $health['overdue_periods']);
        $this->assertEquals(4000.0, $health['overdue_amount']);
        $this->assertSame('2026-10-01', $health['oldest_due_date']);
        $this->assertSame(61, $health['days_overdue']);
        $this->assertTrue($health['suggest_cancellation']);
    }

    public function test_billing_dates_after_the_end_date_are_never_owed(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'auto_renew'        => false,
            'next_billing_date' => '2026-10-01',
            'ends_at'           => '2026-10-15',
        ]);

        $this->assertSame(1, $this->health($subscription)['overdue_periods']);
    }

    public function test_auto_renewal_without_a_valid_card_warns_before_it_fails(): void
    {
        $this->makeCardFor($this->client, ['expiry_month' => '01', 'expiry_year' => '2025']);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        $health = $this->health($subscription);

        $this->assertSame('no_payment_method', $health['state']);
        $this->assertSame('expired', $health['card_issue']);
    }

    public function test_cancelled_subscriptions_are_inactive(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['status' => 'cancelled', 'next_billing_date' => '2026-09-01']);

        $this->assertSame('inactive', $this->health($subscription)['state']);
    }

    // ── Client payment methods ──────────────────────────────────────────────

    public function test_removing_the_last_card_warns_the_client_and_the_admins(): void
    {
        $card = $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('detachPaymentMethod')->once());

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/payment-profiles/{$card->id}")
            ->assertOk()
            ->assertJsonPath('meta.subscriptions_without_card', 1);

        $this->assertSame(1, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_METHOD_MISSING));
        Bus::assertDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class, fn ($job) => $job->subscription_id === $subscription->id);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'link' => "/admin/seo-packages-subscriptions/{$subscription->id}"]);
    }

    public function test_removing_a_card_while_another_valid_one_remains_sends_nothing(): void
    {
        $card = $this->makeCardFor($this->client);
        $this->makeCardFor($this->client, ['is_default' => false, 'last_four' => '1881']);
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('detachPaymentMethod')->once());

        $this->actingAs($this->client, 'api')
            ->deleteJson("/api/payment-profiles/{$card->id}")
            ->assertOk()
            ->assertJsonPath('meta.subscriptions_without_card', 0);

        $this->assertSame(0, $this->clientEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_METHOD_MISSING));
        Bus::assertNotDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class);
    }

    public function test_admin_card_removed_email_goes_to_every_recipient(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($subscription->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof AdminSeoSubscriptionBillingNotification
            && $job->mailable->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_METHOD_REMOVED
            && $job->mailable->recipient_email === $this->admin->email
        );
    }

    public function test_admin_card_removed_email_is_skipped_when_a_card_was_added_back(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->makeCardFor($this->client);

        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($subscription->id))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_adding_a_card_retries_a_declined_renewal_on_the_next_run(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe();
        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertNull($charge->next_retry_at);

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->makeCardFor($this->client);
        $scheduled = app(SeoSubscriptionBillingService::class)->handlePaymentMethodAdded($this->client);

        $this->assertSame(1, $scheduled);
        $this->assertTrue($charge->refresh()->next_retry_at->lte(now()));

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('chargeSavedCardOffSession')
            ->once()
            ->andReturn(['success' => true, 'payment_intent_id' => 'pi_retry', 'status' => 'requires_capture']));
        $this->runBilling();

        $this->assertSame('succeeded', $charge->refresh()->status);
        $this->assertSame('current', $subscription->refresh()->payment_status);
    }

    // ── Admin endpoints ─────────────────────────────────────────────────────

    public function test_list_filters_and_stats_include_payment_pending_subscriptions(): void
    {
        $this->makeCardFor($this->client);
        $pending  = $this->makeSubscriptionFor($this->client, ['auto_renew' => false, 'next_billing_date' => '2026-11-01']);
        $past_due = $this->makeSubscriptionFor($this->client, ['payment_status' => 'past_due']);
        $healthy  = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-01']);

        $pending_ids = collect($this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . '?payment_status=pending')
            ->assertOk()
            ->json('data.data'))->pluck('id');

        $this->assertEquals([$pending->id], $pending_ids->all());

        $attention_ids = collect($this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . '?payment_status=attention')
            ->json('data.data'))->pluck('id')->sort()->values();

        $this->assertEquals(collect([$pending->id, $past_due->id])->sort()->values()->all(), $attention_ids->all());
        $this->assertNotContains($healthy->id, $attention_ids);

        $this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . '/stats')
            ->assertJsonPath('data.payment_pending', 1)
            ->assertJsonPath('data.past_due', 1)
            ->assertJsonPath('data.needs_attention', 2);
    }

    public function test_detail_exposes_payment_health_and_open_invoices(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->getJson(self::ADMIN_URL . "/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'retrying')
            ->assertJsonPath('data.payment_health.failure.code', 'insufficient_funds')
            ->assertJsonCount(1, 'summary.open_invoices');
    }

    public function test_cancelling_keeps_unpaid_invoices_open_by_default(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::ADMIN_URL . "/{$subscription->id}/cancel")
            ->assertOk();

        $this->assertSame('unpaid', Invoice::firstOrFail()->status);
        $this->assertNull(SeoSubscriptionCharge::firstOrFail()->next_retry_at);
    }

    public function test_cancelling_can_void_the_unpaid_invoices(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->patchJson(self::ADMIN_URL . "/{$subscription->id}/cancel", ['void_open_invoices' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $invoice = Invoice::firstOrFail();
        $this->assertSame('void', $invoice->status);
        $this->assertTrue(InvoiceHistory::where('invoice_id', $invoice->id)->where('event', 'invoice voided')->exists());
        $this->assertSame('current', $subscription->refresh()->payment_status);
    }

    // ── Client endpoint ─────────────────────────────────────────────────────

    public function test_client_sees_the_failed_payment_without_raw_processor_messages(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();
        $this->runBilling();

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/seo-packages/subscriptions/active')
            ->assertOk()
            ->assertJsonPath('data.payment_health.state', 'retrying')
            ->assertJsonPath('data.payment_health.failure.reason', 'Your bank declined the payment due to insufficient funds.');

        $this->assertStringNotContainsString('Your card has insufficient funds.', $response->getContent());
        $this->assertNotNull($response->json('data.payment_health.open_invoice.unique_id'));
    }
}
