<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions\PaymentHealth;

use App\Jobs\SendAdminSeoSubscriptionPaymentMethodAlertJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\EmailNotificationSetting;
use App\Models\Notification;
use App\Models\PaymentProfile;
use App\Models\SeoSubscriptionCharge;
use App\Services\SeoSubscriptionBillingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\SeoSubscriptions\PaymentHealthTestCase;

/**
 * A client can remove every saved card, which would make the next automatic
 * renewal fail. Removing is never blocked: the client and the admins are told
 * right away instead, and saving a new card retries a declined renewal.
 */
class PaymentMethodChangesTest extends PaymentHealthTestCase
{
    private function removeCard(PaymentProfile $card)
    {
        return $this->actingAs($this->client, 'api')->deleteJson("/api/payment-profiles/{$card->id}");
    }

    private function mockStripeDetach(): void
    {
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldReceive('detachPaymentMethod'));
    }

    private function cardMissingEmails(): \Illuminate\Support\Collection
    {
        return Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof SeoSubscriptionBillingEmail
            && $job->mailable->variant === SeoSubscriptionBillingEmail::VARIANT_PAYMENT_METHOD_MISSING
        );
    }

    // ── Removing cards ──────────────────────────────────────────────────────

    public function test_removing_the_last_card_is_allowed_and_warns_the_client_and_the_admins(): void
    {
        $card = $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->mockStripeDetach();

        $this->removeCard($card)
            ->assertOk()
            ->assertJsonPath('message', 'Payment method removed successfully.')
            ->assertJsonPath('meta.subscriptions_without_card', 1);

        $this->assertDatabaseMissing('payment_profiles', ['id' => $card->id]);

        $emails = $this->cardMissingEmails();
        $this->assertCount(1, $emails);
        $mail = $emails->first()->mailable;
        $this->assertSame($this->client->email, $mail->client_email);
        $this->assertSame('December 20, 2026', $mail->billing_date);
        $this->assertSame('$2,000.00', $mail->amount);
        $this->assertSame('', $mail->card_label);

        Bus::assertDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class, fn ($job) => $job->subscription_id === $subscription->id);

        $notification = Notification::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame("/admin/seo-packages-subscriptions/{$subscription->id}", $notification->link);
        $this->assertStringContainsString('Cathleen Siech removed their last card', $notification->message);
        $this->assertStringContainsString('Dec 20', $notification->message);
    }

    public function test_the_renewal_card_reference_is_cleared_when_it_is_removed(): void
    {
        $card = $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, ['payment_profile_id' => $card->id]);
        $this->mockStripeDetach();

        $this->removeCard($card)->assertOk();

        $this->assertNull($subscription->refresh()->payment_profile_id);
    }

    public function test_removing_a_card_while_another_valid_one_remains_sends_nothing(): void
    {
        $card = $this->makeCardFor($this->client);
        $this->makeCardFor($this->client, ['is_default' => false, 'last_four' => '1881']);
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->mockStripeDetach();

        $this->removeCard($card)->assertOk()->assertJsonPath('meta.subscriptions_without_card', 0);

        $this->assertCount(0, $this->cardMissingEmails());
        Bus::assertNotDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class);
        $this->assertSame(0, Notification::count());
    }

    public function test_removing_the_last_valid_card_counts_even_if_expired_cards_remain(): void
    {
        $card = $this->makeCardFor($this->client);
        $this->makeCardFor($this->client, ['is_default' => false, 'expiry_month' => '01', 'expiry_year' => '2025']);
        $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $this->mockStripeDetach();

        $this->removeCard($card)->assertOk()->assertJsonPath('meta.subscriptions_without_card', 1);

        $this->assertCount(1, $this->cardMissingEmails());
    }

    public function test_each_auto_renewing_subscription_of_the_client_is_reported(): void
    {
        $card = $this->makeCardFor($this->client);
        $first  = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);
        $second = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-28', 'plan_name' => 'Local SEO']);
        $this->mockStripeDetach();

        $this->removeCard($card)->assertOk()->assertJsonPath('meta.subscriptions_without_card', 2);

        $this->assertCount(2, $this->cardMissingEmails());
        foreach ([$first, $second] as $subscription) {
            Bus::assertDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class, fn ($job) => $job->subscription_id === $subscription->id);
        }
    }

    public function test_manual_billing_cancelled_and_expired_subscriptions_are_not_reported(): void
    {
        $card = $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client, ['auto_renew' => false]);
        $this->makeSubscriptionFor($this->client, ['status' => 'cancelled', 'auto_renew' => false]);
        $this->makeSubscriptionFor($this->client, ['status' => 'expired']);
        $this->mockStripeDetach();

        $this->removeCard($card)->assertOk()->assertJsonPath('meta.subscriptions_without_card', 0);

        $this->assertCount(0, $this->cardMissingEmails());
        Bus::assertNotDispatched(SendAdminSeoSubscriptionPaymentMethodAlertJob::class);
    }

    public function test_a_client_can_not_remove_or_trigger_alerts_with_another_clients_card(): void
    {
        $other_client = $this->makeUserWithRole('client');
        $other_card   = $this->makeCardFor($other_client);
        $this->makeSubscriptionFor($other_client);
        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('detachPaymentMethod'));

        $this->removeCard($other_card)->assertNotFound();

        $this->assertDatabaseHas('payment_profiles', ['id' => $other_card->id]);
        $this->assertCount(0, $this->cardMissingEmails());
    }

    public function test_a_subscription_side_effect_failure_never_blocks_removing_the_card(): void
    {
        $card = $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->mockStripeDetach();

        $billing = Mockery::mock(SeoSubscriptionBillingService::class);
        $billing->shouldReceive('handlePaymentMethodRemoved')->andThrow(new \RuntimeException('Queue is down'));
        $this->app->instance(SeoSubscriptionBillingService::class, $billing);

        $this->removeCard($card)->assertOk()->assertJsonPath('meta.subscriptions_without_card', 0);

        $this->assertDatabaseMissing('payment_profiles', ['id' => $card->id]);
    }

    // ── Admin card removed email ────────────────────────────────────────────

    public function test_admin_card_removed_email_goes_to_every_configured_recipient(): void
    {
        $other_admin = $this->makeUserWithRole('admin');
        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$this->admin->id],
            'custom_emails'     => ['billing@example.com'],
        ]);
        $subscription = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2026-12-20']);

        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($subscription->id))->handle();

        $emails = Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof AdminSeoSubscriptionBillingNotification
            && $job->mailable->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_METHOD_REMOVED
        );

        $this->assertEqualsCanonicalizing(
            [$this->admin->email, 'billing@example.com'],
            $emails->map(fn (SendEmailJob $job) => $job->recipient_email)->all()
        );
        $this->assertNotContains($other_admin->email, $emails->map(fn (SendEmailJob $job) => $job->recipient_email)->all());

        $mail = $emails->first()->mailable;
        $this->assertSame('No card on file', $mail->card_label);
        $this->assertSame('December 20, 2026', $mail->next_billing_date);
        $this->assertStringContainsString('December 20, 2026', $mail->failure_reason);
        $this->assertStringEndsWith("/admin/seo-packages-subscriptions/{$subscription->id}", $mail->subscription_url);
        $this->assertStringNotContainsString('—', $mail->render());
    }

    public function test_admin_card_removed_email_is_skipped_when_it_no_longer_applies(): void
    {
        $cancelled = $this->makeSubscriptionFor($this->client, ['status' => 'cancelled']);
        $manual    = $this->makeSubscriptionFor($this->client, ['auto_renew' => false]);

        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($cancelled->id))->handle();
        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($manual->id))->handle();
        (new SendAdminSeoSubscriptionPaymentMethodAlertJob(999999))->handle();

        $renewing = $this->makeSubscriptionFor($this->client);
        $this->makeCardFor($this->client);
        (new SendAdminSeoSubscriptionPaymentMethodAlertJob($renewing->id))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    // ── Adding cards ────────────────────────────────────────────────────────

    public function test_adding_a_card_retries_a_renewal_that_failed_for_lack_of_a_card(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->mockStripe();
        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('no_payment_method', $charge->failure_code);
        $this->assertNull($charge->next_retry_at, 'Retries are paused while there is no card.');

        Carbon::setTestNow('2026-12-02 10:00:00');
        $this->makeCardFor($this->client);

        $this->assertSame(1, app(SeoSubscriptionBillingService::class)->handlePaymentMethodAdded($this->client));
        $this->assertTrue($charge->refresh()->next_retry_at->lte(now()));

        $this->approveEveryCharge();
        $this->runBilling();

        $this->assertSame('succeeded', $charge->refresh()->status);
        $this->assertSame(2, $charge->attempt_count);
        $this->assertSame('current', $subscription->refresh()->payment_status);
        $this->assertSame('2027-01-01', $subscription->next_billing_date->toDateString());
        $this->assertSame('healthy', $this->health($subscription)['state']);
    }

    public function test_adding_a_card_brings_a_scheduled_retry_forward(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['payment_status' => 'past_due']);
        $charge = $this->makeFailedCharge($subscription, ['next_retry_at' => now()->addDays(5)]);

        app(SeoSubscriptionBillingService::class)->handlePaymentMethodAdded($this->client);

        $this->assertTrue($charge->refresh()->next_retry_at->lte(now()));
    }

    public function test_adding_a_card_leaves_paid_up_manual_and_cancelled_subscriptions_alone(): void
    {
        $paid_up = $this->makeSubscriptionFor($this->client, ['next_billing_date' => '2027-01-01']);

        $manual = $this->makeSubscriptionFor($this->client, ['auto_renew' => false, 'payment_status' => 'past_due']);
        $manual_charge = $this->makeFailedCharge($manual, ['trigger' => 'manual', 'next_retry_at' => null]);

        $cancelled = $this->makeSubscriptionFor($this->client, ['status' => 'cancelled', 'payment_status' => 'past_due']);
        $cancelled_charge = $this->makeFailedCharge($cancelled, ['next_retry_at' => null]);

        $scheduled = app(SeoSubscriptionBillingService::class)->handlePaymentMethodAdded($this->client);

        $this->assertSame(0, $scheduled);
        $this->assertNull($manual_charge->refresh()->next_retry_at);
        $this->assertNull($cancelled_charge->refresh()->next_retry_at);
        $this->assertSame(0, $paid_up->charges()->count());
    }

    public function test_saving_a_card_through_the_api_schedules_the_retry(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client, ['payment_status' => 'past_due']);
        $charge = $this->makeFailedCharge($subscription, ['failure_code' => 'no_payment_method', 'next_retry_at' => null]);

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('retrievePaymentMethod')->andReturn([
                'success'     => true,
                'customer_id' => 'cus_client',
                'card'        => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030],
            ]);
        });

        $this->actingAs($this->client, 'api')
            ->postJson('/api/payment-profiles', ['stripe_payment_method_id' => 'pm_new_card', 'is_default' => true])
            ->assertCreated();

        $this->assertNotNull($charge->refresh()->next_retry_at);
        $this->assertTrue($charge->next_retry_at->lte(now()));
    }
}
