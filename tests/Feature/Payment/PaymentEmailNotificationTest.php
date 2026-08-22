<?php

namespace Tests\Feature\Payment;

use App\Events\PayLaterOrderPlaced;
use App\Events\PaymentCompleted;
use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Jobs\SendAdminPayLaterOrderNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\NotificationEmail;
use App\Mail\PaymentSuccessfulEmail;
use App\Models\DrTier;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PaymentEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

        $this->dr_tier = DrTier::create([
            'id'             => 'dr30',
            'label'          => 'DR 30+',
            'min_dr'         => 30,
            'max_dr'         => 39,
            'traffic_range'  => '5k–10k',
            'word_count'     => 500,
            'price_per_link' => 100.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    private function mockStripe(bool $verified = true, bool $captured = true): void
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('verifyPaymentIntent')
            ->andReturn($verified
                ? ['verified' => true]
                : ['verified' => false, 'message' => 'Payment intent not valid.']);

        $mock->shouldReceive('capturePaymentIntent')
            ->andReturn($captured
                ? ['success' => true]
                : ['success' => false, 'message' => 'Capture failed.']);

        $mock->shouldReceive('cancelPaymentIntent')
            ->andReturn(['success' => true, 'voided' => true]);

        $this->app->instance(StripeService::class, $mock);
    }

    private function baseCheckoutPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id' => 'pi_test_card',
            'total_amount'      => 100.0,
            'session_id'        => (string) Str::uuid(),
            'order_title'       => 'Test Order',
            'order_notes'       => null,
            'billing' => [
                'company'     => 'Test Co',
                'address'     => '123 Main St',
                'city'        => 'Boise',
                'state'       => 'ID',
                'country'     => 'US',
                'postal_code' => '83701',
            ],
            'coupon_ids'                 => [],
            'link_building_items'        => null,
            'content_optimization_items' => null,
            'new_content_items'          => null,
            'content_brief_items'        => null,
            'credits_amount'             => 0,
        ], $overrides);
    }

    private function linkBuildingItem(int $quantity = 1, float $unit_price = 100.0): array
    {
        return [
            'dr_tier_id' => $this->dr_tier->id,
            'quantity'   => $quantity,
            'unit_price' => $unit_price,
            'placements' => array_map(fn ($i) => [
                'row_index'    => $i + 1,
                'keyword'      => 'test keyword ' . ($i + 1),
                'landing_page' => 'https://example.com/page-' . ($i + 1),
                'exact_match'  => false,
            ], range(0, $quantity - 1)),
        ];
    }

    private function createAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    // ─── Card payment notifications ──────────────────────────────────────────

    public function test_card_payment_queues_client_payment_confirmation_email(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, function (PaymentSuccessfulEmail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_card_payment_dispatches_admin_invoice_paid_notification_job(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    public function test_card_payment_fires_payment_completed_event_for_each_admin_recipient(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        Event::fake([PaymentCompleted::class]);
        $this->createAdminUser();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Event::assertDispatched(PaymentCompleted::class);
    }

    public function test_exactly_one_client_confirmation_email_queued_per_card_checkout(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, 1);
    }

    public function test_card_payment_fires_payment_completed_exactly_once_per_admin_recipient(): void
    {
        // Regression test: InvoiceService used to fire PaymentCompleted for
        // every super_admin unconditionally, and CartController fired it a
        // second time (filtered) for the same invoice, so a single checkout
        // produced two "Payment Receipt" emails for the same admin.
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        Event::fake([PaymentCompleted::class]);
        $admin = $this->createAdminUser();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        // One event for the paying client, one for the single admin recipient.
        Event::assertDispatchedTimes(PaymentCompleted::class, 2);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $admin->id);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $this->client->id);
    }

    public function test_card_payment_fires_payment_completed_event_for_the_paying_client(): void
    {
        // Regression test: PaymentCompleted used to be dispatched only for
        // admin recipients, so the paying client never received a "Payment
        // Receipt" portal notification or email for their own purchase.
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        Event::fake([PaymentCompleted::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $this->client->id);
    }

    public function test_card_payment_receipt_email_links_to_the_client_invoice_view(): void
    {
        // The client's own "Payment Receipt" email must link to their
        // client-portal invoice page, not the admin-only invoice view.
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class, SendEmailJob::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            if ($job->recipient_email !== $this->client->email || ! ($job->mailable instanceof NotificationEmail)) {
                return false;
            }

            $mail_data = $job->mailable->mail_data;

            return str_contains($mail_data['invoice_url'] ?? '', '/invoices/')
                && ! str_contains($mail_data['invoice_url'] ?? '', '/admin/invoices/');
        });
    }

    public function test_card_payment_only_notifies_admins_enabled_in_email_notification_settings(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        Event::fake([PaymentCompleted::class]);
        $enabled_admin  = $this->createAdminUser();
        $excluded_admin = $this->createAdminUser();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$enabled_admin->id],
        ]);

        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        // One event for the paying client, one for the single enabled admin.
        Event::assertDispatchedTimes(PaymentCompleted::class, 2);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $enabled_admin->id);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $this->client->id);
        Event::assertNotDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $excluded_admin->id);
    }

    public function test_card_payment_receipt_email_links_to_the_admin_invoice_view(): void
    {
        // Regression test: the "Payment Receipt" email is only ever sent to
        // admin recipients (see DispatchesAdminPaymentNotifications), but its
        // "View in your account" button and line item links used to point at
        // the client-only /invoices/{unique_id} route, so an admin clicking
        // through landed on a page that was not theirs to view. It must link
        // to the admin invoice detail page instead, and must not advertise a
        // PDF download link since no such route exists.
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class, SendEmailJob::class]);
        $admin = $this->createAdminUser();
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin) {
            if ($job->recipient_email !== $admin->email || ! ($job->mailable instanceof NotificationEmail)) {
                return false;
            }

            $mail_data = $job->mailable->mail_data;

            return str_contains($mail_data['invoice_url'] ?? '', '/admin/invoices/')
                && ! array_key_exists('invoice_pdf_url', $mail_data);
        });
    }

    public function test_card_payment_notifies_every_recipient_configured_in_email_notification_settings(): void
    {
        // Confirms the fix scales to any number of configured recipients, not
        // just a single admin: two enabled admins and a custom email address
        // must all receive both the "Payment Received" email and the in-app
        // "Payment Receipt" notification, while a third, non-enabled admin
        // must receive neither.
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class, SendEmailJob::class]);
        Event::fake([PaymentCompleted::class]);

        $enabled_admin_one = $this->createAdminUser();
        $enabled_admin_two = $this->createAdminUser();
        $excluded_admin    = $this->createAdminUser();
        $custom_email      = 'billing@agency.com';

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$enabled_admin_one->id, $enabled_admin_two->id],
            'custom_emails'     => [$custom_email],
        ]);

        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        // "Payment Received" job is dispatched once; run its handler for real
        // (it was faked above purely to stop it from actually mailing) and
        // assert it queues an email for every configured recipient.
        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
        $invoice = Invoice::where('user_id', $this->client->id)->firstOrFail();
        (new SendAdminInvoicePaidNotificationJob($invoice->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $enabled_admin_one->email);
        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $enabled_admin_two->email);
        Bus::assertDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $custom_email);
        Bus::assertNotDispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $excluded_admin->email);

        // The in-app "Payment Receipt" event fires once for the paying client
        // and once per enabled admin user, but never for the custom email (it
        // has no portal account to notify in-app) and never for the excluded
        // admin.
        Event::assertDispatchedTimes(PaymentCompleted::class, 3);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $this->client->id);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $enabled_admin_one->id);
        Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $enabled_admin_two->id);
        Event::assertNotDispatched(PaymentCompleted::class, fn (PaymentCompleted $event) => $event->user->id === $excluded_admin->id);
    }

    // ─── Credits payment notifications ───────────────────────────────────────

    public function test_credits_payment_queues_client_payment_confirmation_email(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        $this->client->update(['credit_balance' => 500.0]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'payment_method_id'   => 'credits_pay_100',
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, function (PaymentSuccessfulEmail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_credits_payment_dispatches_admin_invoice_paid_notification_job(): void
    {
        Mail::fake();
        Bus::fake();
        $this->client->update(['credit_balance' => 500.0]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'payment_method_id'   => 'credits_pay_100',
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    // ─── Hybrid payment notifications ─────────────────────────────────────────

    public function test_hybrid_payment_queues_client_payment_confirmation_email(): void
    {
        Mail::fake();
        Bus::fake([SendAdminInvoicePaidNotificationJob::class]);
        $this->mockStripe();
        $this->client->update(['credit_balance' => 50.0]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'payment_method_id'   => 'pi_hybrid_test',
                'credits_amount'      => 50.0,
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Mail::assertQueued(PaymentSuccessfulEmail::class, function (PaymentSuccessfulEmail $mail) {
            return $mail->hasTo($this->client->email);
        });
    }

    public function test_hybrid_payment_dispatches_admin_invoice_paid_notification_job(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe();
        $this->client->update(['credit_balance' => 50.0]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'payment_method_id'   => 'pi_hybrid_test',
                'credits_amount'      => 50.0,
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminInvoicePaidNotificationJob::class);
    }

    // ─── Pay-later (deferred) notifications ──────────────────────────────────

    public function test_pay_later_checkout_dispatches_admin_pay_later_notification_job(): void
    {
        Mail::fake();
        Bus::fake([SendAdminPayLaterOrderNotificationJob::class]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'    => true,
                'total_amount'        => 100.0,
                'session_id'          => (string) Str::uuid(),
                'order_title'         => 'Pay Later Order',
                'link_building_items' => [$this->linkBuildingItem()],
            ])
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminPayLaterOrderNotificationJob::class);
    }

    public function test_pay_later_checkout_does_not_queue_client_payment_confirmation_email(): void
    {
        Mail::fake();
        Bus::fake([SendAdminPayLaterOrderNotificationJob::class]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'    => true,
                'total_amount'        => 100.0,
                'session_id'          => (string) Str::uuid(),
                'order_title'         => 'Pay Later Order',
                'link_building_items' => [$this->linkBuildingItem()],
            ])
            ->assertStatus(200);

        Mail::assertNotQueued(PaymentSuccessfulEmail::class);
    }

    public function test_pay_later_checkout_fires_pay_later_order_placed_event_for_admin_recipients(): void
    {
        Mail::fake();
        Bus::fake([SendAdminPayLaterOrderNotificationJob::class]);
        Event::fake([PayLaterOrderPlaced::class]);
        $this->createAdminUser();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout/deferred', [
                'deferred_payment'    => true,
                'total_amount'        => 100.0,
                'session_id'          => (string) Str::uuid(),
                'order_title'         => 'Pay Later Order',
                'link_building_items' => [$this->linkBuildingItem()],
            ])
            ->assertStatus(200);

        Event::assertDispatched(PayLaterOrderPlaced::class);
    }

    // ─── Failed payment — no notifications sent ───────────────────────────────

    public function test_failed_stripe_verification_does_not_queue_any_email_notifications(): void
    {
        Mail::fake();
        Bus::fake();
        $this->mockStripe(verified: false);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(402);

        Mail::assertNothingQueued();
        Bus::assertNothingDispatched();
    }

    public function test_insufficient_credits_does_not_queue_any_email_notifications(): void
    {
        Mail::fake();
        Bus::fake();
        $this->client->update(['credit_balance' => 10.0]);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/cart/checkout', $this->baseCheckoutPayload([
                'payment_method_id'   => 'credits_pay_100',
                'link_building_items' => [$this->linkBuildingItem()],
            ]))
            ->assertStatus(422);

        Mail::assertNothingQueued();
        Bus::assertNothingDispatched();
    }
}
