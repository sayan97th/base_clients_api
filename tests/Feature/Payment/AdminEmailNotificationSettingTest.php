<?php

namespace Tests\Feature\Payment;

use App\Jobs\SendAdminInvoicePaidNotificationJob;
use App\Jobs\SendAdminPayLaterOrderNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminInvoicePaidNotification;
use App\Mail\AdminPayLaterOrderNotification;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailNotificationSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AdminEmailNotificationSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'super_admin'],  ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'client'],       ['display_name' => 'Client',      'description' => 'Client']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    private function makeAdmin(bool $active = true): User
    {
        $admin = User::factory()->create(['is_active' => $active]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'unique_id'       => strtoupper(bin2hex(random_bytes(4))),
            'invoice_number'  => 'BSM-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
            'user_id'         => $this->client->id,
            'status'          => 'paid',
            'payment_method'  => 'Credit Card',
            'currency_type'   => 'usd',
            'subtotal_amount' => 100.0,
            'discount_amount' => 0.0,
            'total_amount'    => 100.0,
            'credit_amount'   => 0.0,
            'date_issued'     => now(),
            'date_paid'       => now(),
        ], $overrides));
    }

    // ─── EmailNotificationSettingService::resolveAdminRecipients() ───────────

    public function test_resolve_recipients_returns_all_active_admins_when_notify_all_is_true(): void
    {
        $admin_a = $this->makeAdmin();
        $admin_b = $this->makeAdmin();
        $this->makeAdmin(active: false); // inactive — must be excluded

        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains($admin_a->email, $emails);
        $this->assertContains($admin_b->email, $emails);
        $this->assertCount(2, $emails);
    }

    public function test_resolve_recipients_returns_only_selected_users_when_notify_all_is_false(): void
    {
        $selected = $this->makeAdmin();
        $excluded = $this->makeAdmin();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$selected->id],
        ]);

        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains($selected->email, $emails);
        $this->assertNotContains($excluded->email, $emails);
    }

    public function test_resolve_recipients_includes_custom_emails(): void
    {
        $admin = $this->makeAdmin();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => ['custom@example.com', 'billing@agency.com'],
        ]);

        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains('custom@example.com', $emails);
        $this->assertContains('billing@agency.com', $emails);
        $this->assertNotContains($admin->email, $emails);
    }

    public function test_resolve_recipients_combines_selected_users_and_custom_emails(): void
    {
        $selected = $this->makeAdmin();

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$selected->id],
            'custom_emails'     => ['extra@example.com'],
        ]);

        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains($selected->email, $emails);
        $this->assertContains('extra@example.com', $emails);
        $this->assertCount(2, $emails);
    }

    public function test_resolve_recipients_defaults_to_all_admins_when_no_settings_record_exists(): void
    {
        $admin_a = $this->makeAdmin();
        $admin_b = $this->makeAdmin();

        // No EmailNotificationSetting record — service should fall back to notify_all_admins=true
        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains($admin_a->email, $emails);
        $this->assertContains($admin_b->email, $emails);
    }

    public function test_resolve_recipients_excludes_inactive_admin_users(): void
    {
        $active   = $this->makeAdmin(active: true);
        $inactive = $this->makeAdmin(active: false);

        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $recipients = EmailNotificationSettingService::resolveAdminRecipients();
        $emails     = array_column($recipients, 'email');

        $this->assertContains($active->email, $emails);
        $this->assertNotContains($inactive->email, $emails);
    }

    // ─── SendAdminInvoicePaidNotificationJob ─────────────────────────────────

    public function test_invoice_paid_job_dispatches_send_email_job_to_each_admin_recipient(): void
    {
        Bus::fake([SendEmailJob::class]);

        $admin_a = $this->makeAdmin();
        $admin_b = $this->makeAdmin();
        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $invoice = $this->makeInvoice();
        (new SendAdminInvoicePaidNotificationJob($invoice->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin_a) {
            return $job->recipient_email === $admin_a->email
                && $job->mailable instanceof AdminInvoicePaidNotification;
        });

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin_b) {
            return $job->recipient_email === $admin_b->email
                && $job->mailable instanceof AdminInvoicePaidNotification;
        });

        Bus::assertDispatched(SendEmailJob::class, 2);
    }

    public function test_invoice_paid_job_dispatches_no_emails_when_no_recipients_configured(): void
    {
        Bus::fake([SendEmailJob::class]);

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => [],
        ]);

        $invoice = $this->makeInvoice();
        (new SendAdminInvoicePaidNotificationJob($invoice->id))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_invoice_paid_job_dispatches_email_to_custom_address(): void
    {
        Bus::fake([SendEmailJob::class]);

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => ['alerts@agency.com'],
        ]);

        $invoice = $this->makeInvoice();
        (new SendAdminInvoicePaidNotificationJob($invoice->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            return $job->recipient_email === 'alerts@agency.com'
                && $job->mailable instanceof AdminInvoicePaidNotification;
        });
    }

    public function test_invoice_paid_job_does_nothing_when_invoice_not_found(): void
    {
        Bus::fake([SendEmailJob::class]);

        (new SendAdminInvoicePaidNotificationJob('non-existent-uuid'))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    // ─── SendAdminPayLaterOrderNotificationJob ────────────────────────────────

    public function test_pay_later_job_dispatches_send_email_job_to_each_admin_recipient(): void
    {
        Bus::fake([SendEmailJob::class]);

        $admin = $this->makeAdmin();
        EmailNotificationSetting::create(['notify_all_admins' => true]);

        $invoice = $this->makeInvoice(['status' => 'unpaid', 'date_paid' => null]);
        (new SendAdminPayLaterOrderNotificationJob($invoice->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) use ($admin) {
            return $job->recipient_email === $admin->email
                && $job->mailable instanceof AdminPayLaterOrderNotification;
        });
    }

    public function test_pay_later_job_dispatches_no_emails_when_no_recipients_configured(): void
    {
        Bus::fake([SendEmailJob::class]);

        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [],
            'custom_emails'     => [],
        ]);

        $invoice = $this->makeInvoice(['status' => 'unpaid', 'date_paid' => null]);
        (new SendAdminPayLaterOrderNotificationJob($invoice->id))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }

    public function test_pay_later_job_does_nothing_when_invoice_not_found(): void
    {
        Bus::fake([SendEmailJob::class]);

        (new SendAdminPayLaterOrderNotificationJob('non-existent-uuid'))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }
}
