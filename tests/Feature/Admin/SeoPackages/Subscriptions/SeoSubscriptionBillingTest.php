<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions;

use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Jobs\SendAdminSeoSubscriptionBillingNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Models\EmailNotificationSetting;
use App\Mail\PaymentSuccessfulEmail;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\Invoice;
use App\Models\PaymentProfile;
use App\Models\Role;
use App\Models\SeoPackage;
use App\Models\SeoPackageSubscription;
use App\Models\SeoSubscriptionCharge;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeoSubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private SeoPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-01 15:00:00');

        Role::firstOrCreate(['name' => 'admin'],  ['display_name' => 'Admin',  'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client']);

        $this->admin  = User::factory()->create(['is_active' => true]);
        $this->client = User::factory()->create(['is_active' => true, 'stripe_customer_id' => 'cus_client']);

        $this->admin->assignRole('admin');
        $this->client->assignRole('client');

        $this->package = SeoPackage::create([
            'id'              => 'growth',
            'name'            => 'Growth',
            'slug'            => 'growth',
            'price_per_month' => 1499,
            'best_for'        => 'Growing brands',
            'features'        => [],
            'is_active'       => true,
        ]);

        if (DB::table('invoice_number_counters')->count() === 0) {
            DB::table('invoice_number_counters')->insert(['next_number' => 1000]);
        }

        Mail::fake();
        Bus::fake();
        Event::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeCard(array $overrides = []): PaymentProfile
    {
        return PaymentProfile::create(array_merge([
            'user_id'                  => $this->client->id,
            'stripe_payment_method_id' => 'pm_' . bin2hex(random_bytes(4)),
            'card_brand'               => 'visa',
            'last_four'                => '4242',
            'expiry_month'             => '12',
            'expiry_year'              => '2030',
            'is_default'               => true,
        ], $overrides));
    }

    private function makeSubscription(array $overrides = []): SeoPackageSubscription
    {
        return SeoPackageSubscription::create(array_merge([
            'user_id'              => $this->client->id,
            'seo_package_id'       => null,
            'plan_name'            => 'Custom SEO Package',
            'pricing_type'         => 'custom',
            'monthly_price'        => 2000,
            'auto_renew'           => true,
            'billing_anchor_day'   => 1,
            'next_billing_date'    => '2026-12-01',
            'reminder_days_before' => 3,
            'payment_status'       => 'current',
            'status'               => 'active',
            'starts_at'            => '2026-11-01',
        ], $overrides));
    }

    private function mockStripe(callable $configure): MockInterface
    {
        $mock = Mockery::mock(StripeService::class);
        $mock->shouldReceive('findOrCreateCustomer')->andReturn(['success' => true, 'customer_id' => 'cus_client'])->byDefault();
        $mock->shouldReceive('retrievePaymentMethod')->andReturn(['success' => true, 'customer_id' => 'cus_client', 'card' => []])->byDefault();
        $mock->shouldReceive('capturePaymentIntent')->andReturn(['success' => true])->byDefault();
        $mock->shouldReceive('cancelPaymentIntent')->andReturn(['success' => true, 'voided' => true])->byDefault();
        $configure($mock);
        $this->app->instance(StripeService::class, $mock);

        return $mock;
    }

    private function runBilling(): void
    {
        $this->artisan('seo-subscriptions:process-billing')->assertSuccessful();
    }

    private function billingEmailsOf(string $variant): int
    {
        return Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof SeoSubscriptionBillingEmail && $job->mailable->variant === $variant
        )->count();
    }

    // ── Activation ──────────────────────────────────────────────────────────

    public function test_admin_can_activate_a_subscription_with_a_custom_price(): void
    {
        $card = $this->makeCard();

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/seo-packages/subscriptions', [
                'user_id'            => $this->client->id,
                'pricing_type'       => 'custom',
                'custom_price'       => 2000,
                'custom_plan_name'   => 'Custom Growth Plus',
                'invoice_item_label' => 'SEO Services',
                'starts_at'          => '2026-11-01',
                'first_billing_date' => '2026-12-15',
                'auto_renew'         => true,
                'payment_profile_id' => $card->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan_name', 'Custom Growth Plus')
            ->assertJsonPath('data.pricing_type', 'custom')
            ->assertJsonPath('data.monthly_price', 2000)
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.next_billing_date', '2026-12-15')
            ->assertJsonPath('data.package', null);

        $this->assertDatabaseHas('seo_package_subscriptions', [
            'user_id'            => $this->client->id,
            'billing_anchor_day' => 15,
        ]);
    }

    public function test_package_pricing_uses_the_listed_package_price(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/seo-packages/subscriptions', [
                'user_id'      => $this->client->id,
                'pricing_type' => 'package',
                'package_id'   => $this->package->id,
                'starts_at'    => '2026-12-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.plan_name', 'Growth')
            ->assertJsonPath('data.monthly_price', 1499)
            ->assertJsonPath('data.auto_renew', false);
    }

    public function test_auto_renew_requires_a_card_on_file(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/seo-packages/subscriptions', [
                'user_id'      => $this->client->id,
                'pricing_type' => 'custom',
                'custom_price' => 2000,
                'starts_at'    => '2026-12-01',
                'auto_renew'   => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_profile_id']);
    }

    // ── Renewals ────────────────────────────────────────────────────────────

    public function test_due_renewal_charges_the_card_and_creates_the_monthly_invoice(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->withArgs(fn (...$args) => $args[0] === 200000
                    && str_starts_with((string) ($args[4] ?? ''), 'seo-subscription-charge-'))
                ->andReturn(['success' => true, 'payment_intent_id' => 'pi_renewal']);
        });

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('succeeded', $charge->status);
        $this->assertSame('pi_renewal', $charge->payment_intent_id);
        $this->assertSame('2026-12-31', $charge->period_end->toDateString());

        $invoice = Invoice::with('lineItems')->findOrFail($charge->invoice_id);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(2000.0, $invoice->total_amount);
        $this->assertSame('December - SEO Services', $invoice->lineItems->first()->item_name);

        $subscription->refresh();
        $this->assertSame('2027-01-01', $subscription->next_billing_date->toDateString());
        $this->assertSame('current', $subscription->payment_status);

        $this->assertSame(1, $this->billingEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_SUCCEEDED));
        Mail::assertNotQueued(PaymentSuccessfulEmail::class);

        // Admins get the subscription email instead of the generic invoice paid one.
        Bus::assertDispatched(SendAdminSeoSubscriptionBillingNotificationJob::class, fn ($job) =>
            $job->charge_id === $charge->id && $job->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_SUCCEEDED);
        Bus::assertNotDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    public function test_running_the_billing_twice_never_charges_twice(): void
    {
        $this->makeCard();
        $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => true, 'payment_intent_id' => 'pi_once']);
        });

        $this->runBilling();
        $this->runBilling();

        $this->assertSame(1, SeoSubscriptionCharge::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_subscriptions_without_auto_renew_are_not_charged(): void
    {
        $this->makeCard();
        $this->makeSubscription(['auto_renew' => false]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling();

        $this->assertSame(0, SeoSubscriptionCharge::count());
    }

    public function test_declined_renewal_is_retried_on_the_dunning_schedule(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->twice()
                ->andReturn(
                    ['success' => false, 'message' => 'Insufficient funds.', 'error_code' => 'insufficient_funds', 'payment_intent_id' => 'pi_fail'],
                    ['success' => true, 'payment_intent_id' => 'pi_retry'],
                );
        });

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('failed', $charge->status);
        $this->assertSame(1, $charge->attempt_count);
        $this->assertSame('2026-12-04', $charge->next_retry_at->toDateString());
        $this->assertSame('past_due', $subscription->refresh()->payment_status);
        $this->assertSame(1, $this->billingEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED));
        Bus::assertDispatched(SendAdminSeoSubscriptionBillingNotificationJob::class, fn ($job) =>
            $job->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED);

        // Same day: waits for the retry date, no second attempt.
        $this->runBilling();
        $this->assertSame(1, $charge->refresh()->attempt_count);

        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->runBilling();

        $charge->refresh();
        $this->assertSame('succeeded', $charge->status);
        $this->assertSame(2, $charge->attempt_count);
        $this->assertSame(1, Invoice::count(), 'The retry reuses the same invoice.');
        $this->assertSame('current', $subscription->refresh()->payment_status);
        $this->assertSame('2027-01-01', $subscription->next_billing_date->toDateString());
    }

    public function test_declines_a_retry_cannot_fix_are_not_retried(): void
    {
        $this->makeCard();
        $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Authentication required.', 'error_code' => 'authentication_required']);
        });

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertSame('failed', $charge->status);
        $this->assertNull($charge->next_retry_at);

        Carbon::setTestNow('2026-12-10 15:00:00');
        $this->runBilling();

        $this->assertSame(1, $charge->refresh()->attempt_count);
    }

    public function test_invoice_paid_from_the_portal_is_reconciled_without_charging(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Declined.', 'error_code' => 'card_declined']);
        });

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        Invoice::whereKey($charge->invoice_id)->update(['status' => 'paid', 'date_paid' => now()]);

        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->runBilling();

        $this->assertSame('succeeded', $charge->refresh()->status);
        $this->assertSame('2027-01-01', $subscription->refresh()->next_billing_date->toDateString());
    }

    public function test_cancelling_stops_pending_retries(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription();

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => false, 'message' => 'Declined.', 'error_code' => 'card_declined']);
        });

        $this->runBilling();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/seo-packages/subscriptions/{$subscription->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.auto_renew', false);

        $this->assertNull(SeoSubscriptionCharge::firstOrFail()->next_retry_at);

        Carbon::setTestNow('2026-12-04 15:00:00');
        $this->runBilling();
    }

    // ── Manual charge ───────────────────────────────────────────────────────

    public function test_admin_can_charge_the_current_period_manually(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription(['auto_renew' => false]);

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => true, 'payment_intent_id' => 'pi_manual']);
        });

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/seo-packages/subscriptions/{$subscription->id}/charge")
            ->assertOk()
            ->assertJsonPath('data.next_billing_date', '2027-01-01')
            ->assertJsonPath('data.charges.0.status', 'succeeded')
            ->assertJsonPath('data.charges.0.trigger', 'manual')
            ->assertJsonPath('summary.total_collected', 2000);

        $this->assertSame(1, SeoSubscriptionCharge::whereDate('period_start', '2026-12-01')->count());
    }

    public function test_manual_charge_failure_returns_the_decline_message(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription(['auto_renew' => false]);

        $this->mockStripe(function (MockInterface $mock) {
            $mock->shouldReceive('chargeSavedCardOffSession')
                ->once()
                ->andReturn(['success' => false, 'message' => 'The card was declined.', 'error_code' => 'card_declined']);
        });

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/seo-packages/subscriptions/{$subscription->id}/charge")
            ->assertStatus(402)
            ->assertJsonPath('message', 'The card was declined.');

        // Manual attempts are reported to the admin, the client is not emailed.
        $this->assertSame(0, $this->billingEmailsOf(SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED));
    }

    public function test_admin_email_reaches_every_recipient_in_email_notification_settings(): void
    {
        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$this->admin->id],
            'custom_emails'     => ['billing@example.com'],
        ]);

        $this->client->update(['company' => 'Printed Image']);
        $subscription = $this->makeSubscription();
        $charge       = SeoSubscriptionCharge::create([
            'seo_package_subscription_id' => $subscription->id,
            'user_id'                     => $this->client->id,
            'period_start'                => '2026-12-01',
            'period_end'                  => '2026-12-31',
            'amount'                      => 2000,
            'status'                      => 'failed',
            'trigger'                     => 'automatic',
            'attempt_count'               => 1,
            'card_label'                  => 'Visa ending in 4242',
            'failure_message'             => 'Insufficient funds.',
            'next_retry_at'               => '2026-12-04 15:00:00',
        ]);

        (new SendAdminSeoSubscriptionBillingNotificationJob($charge->id, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED))->handle();

        foreach ([$this->admin->email, 'billing@example.com'] as $email) {
            Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($email) {
                if ($job->recipient_email !== $email || ! $job->mailable instanceof AdminSeoSubscriptionBillingNotification) {
                    return false;
                }

                $html = $job->mailable->render();

                return $job->mailable->client_company === 'Printed Image'
                    && str_contains($html, 'Insufficient funds.')
                    && str_contains($html, 'December 4, 2026');
            });
        }
    }

    // ── Reminders and schedule ──────────────────────────────────────────────

    public function test_renewal_reminder_is_sent_once_before_the_billing_date(): void
    {
        $this->makeCard();
        $this->makeSubscription(['next_billing_date' => '2026-12-04']);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling();
        $this->runBilling();

        $this->assertSame(1, $this->billingEmailsOf(SeoSubscriptionBillingEmail::VARIANT_RENEWAL_REMINDER));
    }

    public function test_subscriptions_past_their_end_date_expire(): void
    {
        $subscription = $this->makeSubscription(['ends_at' => '2026-11-30', 'auto_renew' => false]);

        $this->mockStripe(fn (MockInterface $mock) => $mock->shouldNotReceive('chargeSavedCardOffSession'));

        $this->runBilling();

        $this->assertSame('expired', $subscription->refresh()->status);
    }

    public function test_billing_anchor_day_is_clamped_to_short_months(): void
    {
        $feb = SeoPackageSubscription::advanceByOneMonth(Carbon::parse('2027-01-31'), 31);
        $mar = SeoPackageSubscription::advanceByOneMonth($feb, 31);

        $this->assertSame('2027-02-28', $feb->toDateString());
        $this->assertSame('2027-03-31', $mar->toDateString());
    }

    public function test_admin_can_turn_auto_renew_off_and_change_the_price(): void
    {
        $this->makeCard();
        $subscription = $this->makeSubscription();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/seo-packages/subscriptions/{$subscription->id}", [
                'auto_renew'   => false,
                'pricing_type' => 'custom',
                'custom_price' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.monthly_price', 2500);

        $this->assertNotNull($subscription->refresh()->auto_renew_disabled_at);
    }
}
