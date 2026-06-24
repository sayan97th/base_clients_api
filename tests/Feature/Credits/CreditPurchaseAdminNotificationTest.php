<?php

namespace Tests\Feature\Credits;

use App\Jobs\SendAdminCreditPurchaseNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminCreditPurchaseNotification;
use App\Models\CreditPackage;
use App\Models\EmailNotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class CreditPurchaseAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private CreditPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Regular client']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff member']);

        $this->client = User::factory()->create(['is_active' => true, 'credit_balance' => 0]);
        $this->client->assignRole('client');

        $this->package = CreditPackage::create([
            'id'             => 'pro-1000',
            'name'           => 'Pro 1000',
            'credits'        => 1000,
            'price'          => 89.99,
            'original_price' => 99.99,
            'discount_pct'   => 10,
            'description'    => '1000 credits',
            'is_popular'     => true,
            'is_active'      => true,
        ]);
    }

    private function makeAdmin(bool $active = true): User
    {
        $admin = User::factory()->create(['is_active' => $active]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function mockStripe(bool $verified = true, bool $captured = true): void
    {
        $mock = Mockery::mock(StripeService::class);

        $mock->shouldReceive('verifyPaymentIntent')
            ->andReturn($verified
                ? ['verified' => true]
                : ['verified' => false, 'message' => 'Payment verification failed.']);

        $mock->shouldReceive('capturePaymentIntent')
            ->andReturn($captured
                ? ['success' => true]
                : ['success' => false, 'message' => 'Capture failed.']);

        $mock->shouldReceive('cancelPaymentIntent')
            ->andReturn(['success' => true]);

        $this->app->instance(StripeService::class, $mock);
    }

    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'package_id'        => $this->package->id,
            'credits_amount'    => 1000,
            'amount_paid'       => 89.99,
            'payment_intent_id' => 'pi_test_admin_notif_' . uniqid(),
        ], $overrides);
    }

    // ─── Job dispatch on successful purchase ─────────────────────────────────

    public function test_successful_credit_purchase_dispatches_admin_notification_job(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminCreditPurchaseNotificationJob::class);
    }

    public function test_admin_notification_job_dispatched_with_correct_purchase_id(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        Bus::assertDispatched(SendAdminCreditPurchaseNotificationJob::class, function (SendAdminCreditPurchaseNotificationJob $job) use ($purchase_id) {
            return $job->credit_purchase_id === $purchase_id;
        });
    }

    public function test_exactly_one_admin_notification_job_dispatched_per_purchase(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminCreditPurchaseNotificationJob::class, 1);
    }

    // ─── No job on failure scenarios ──────────────────────────────────────────

    public function test_failed_stripe_verification_does_not_dispatch_admin_notification_job(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe(verified: false);

        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(422);

        Bus::assertNotDispatched(SendAdminCreditPurchaseNotificationJob::class);
    }

    public function test_duplicate_payment_intent_does_not_dispatch_admin_notification_job(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $payload = $this->purchasePayload(['payment_intent_id' => 'pi_duplicate_admin_123']);

        // First purchase succeeds
        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $payload)
            ->assertStatus(200);

        Bus::assertDispatched(SendAdminCreditPurchaseNotificationJob::class, 1);
        Bus::fake(); // reset

        // Second attempt with duplicate payment_intent_id is rejected
        $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $payload)
            ->assertStatus(409);

        Bus::assertNotDispatched(SendAdminCreditPurchaseNotificationJob::class);
    }

    public function test_unauthenticated_request_does_not_dispatch_admin_notification_job(): void
    {
        Bus::fake([SendAdminCreditPurchaseNotificationJob::class]);

        $this->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(401);

        Bus::assertNotDispatched(SendAdminCreditPurchaseNotificationJob::class);
    }

    // ─── Job handler: sends emails to admin recipients ────────────────────────

    public function test_job_handler_sends_email_to_each_admin_when_notify_all_is_true(): void
    {
        Bus::fake([SendEmailJob::class, SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $admin_a = $this->makeAdmin();
        $admin_b = $this->makeAdmin();
        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin_a) {
            return $job->recipient_email === $admin_a->email
                && $job->mailable instanceof AdminCreditPurchaseNotification;
        });

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin_b) {
            return $job->recipient_email === $admin_b->email
                && $job->mailable instanceof AdminCreditPurchaseNotification;
        });

        Bus::assertDispatched(SendEmailJob::class, 2);
    }

    public function test_job_handler_sends_email_only_to_selected_recipients(): void
    {
        Bus::fake([SendEmailJob::class, SendAdminCreditPurchaseNotificationJob::class]);
        $this->mockStripe();

        $selected = $this->makeAdmin();
        $excluded = $this->makeAdmin();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$selected->id],
            'custom_emails'     => [],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($selected) {
            return $job->recipient_email === $selected->email;
        });

        Bus::assertDispatched(SendEmailJob::class, 1);

        Bus::assertNotDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($excluded) {
            return $job->recipient_email === $excluded->email;
        });
    }

    public function test_job_handler_sends_email_to_custom_email_addresses(): void
    {
        Bus::fake([SendEmailJob::class]);
        $this->mockStripe();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => ['credits-alert@agency.com'],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            return $job->recipient_email === 'credits-alert@agency.com'
                && $job->mailable instanceof AdminCreditPurchaseNotification;
        });
    }

    public function test_job_handler_sends_no_emails_when_no_recipients_configured(): void
    {
        Bus::fake([SendEmailJob::class]);
        $this->mockStripe();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => [],
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_job_handler_does_nothing_when_purchase_not_found(): void
    {
        Bus::fake([SendEmailJob::class]);

        (new SendAdminCreditPurchaseNotificationJob(999999))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_job_handler_email_contains_correct_client_and_purchase_data(): void
    {
        Bus::fake([SendEmailJob::class]);
        $this->mockStripe();

        $admin = $this->makeAdmin();
        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload([
                'credits_amount' => 1000,
                'amount_paid'    => 89.99,
            ]))
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            if (! ($job->mailable instanceof AdminCreditPurchaseNotification)) {
                return false;
            }

            $mail = $job->mailable;

            return $mail->credits_amount  === 1000
                && $mail->package_name    === $this->package->name
                && $mail->client_email    === $this->client->email;
        });
    }

    public function test_job_handler_excludes_inactive_admins_from_recipients(): void
    {
        Bus::fake([SendEmailJob::class]);
        $this->mockStripe();

        $active_admin   = $this->makeAdmin(active: true);
        $inactive_admin = $this->makeAdmin(active: false);

        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $response = $this->actingAs($this->client, 'api')
            ->postJson('/api/credits/purchase', $this->purchasePayload())
            ->assertStatus(200);

        $purchase_id = $response->json('purchase_id');

        (new SendAdminCreditPurchaseNotificationJob($purchase_id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($active_admin) {
            return $job->recipient_email === $active_admin->email;
        });

        Bus::assertNotDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($inactive_admin) {
            return $job->recipient_email === $inactive_admin->email;
        });
    }
}
