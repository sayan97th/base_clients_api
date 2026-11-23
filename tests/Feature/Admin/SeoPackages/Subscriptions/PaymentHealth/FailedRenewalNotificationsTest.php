<?php

namespace Tests\Feature\Admin\SeoPackages\Subscriptions\PaymentHealth;

use App\Jobs\SendAdminSeoSubscriptionBillingNotificationJob;
use App\Jobs\SendEmailJob;
use App\Mail\AdminSeoSubscriptionBillingNotification;
use App\Mail\SeoSubscriptionBillingEmail;
use App\Models\EmailNotificationSetting;
use App\Models\Notification;
use App\Models\SeoSubscriptionCharge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Support\SeoSubscriptions\PaymentHealthTestCase;

/**
 * When an automatic renewal fails, the admins in Email Notification Settings
 * get an email with the decline reason (plus an in-app notification), and the
 * client is asked to check their payment method. Admins charging by hand see
 * the result on screen, so nothing is emailed for manual charges.
 */
class FailedRenewalNotificationsTest extends PaymentHealthTestCase
{
    private function adminFailureJobs(): \Illuminate\Support\Collection
    {
        return Bus::dispatched(SendAdminSeoSubscriptionBillingNotificationJob::class, fn ($job) =>
            $job->variant === AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED
        );
    }

    private function clientFailureEmails(): \Illuminate\Support\Collection
    {
        return Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) =>
            $job->mailable instanceof SeoSubscriptionBillingEmail
            && $job->mailable->variant === SeoSubscriptionBillingEmail::VARIANT_PAYMENT_FAILED
        );
    }

    // ── Automatic renewals ──────────────────────────────────────────────────

    public function test_a_failed_auto_renewal_notifies_the_admins_by_email_and_in_app(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $this->runBilling();

        $charge = SeoSubscriptionCharge::firstOrFail();
        $this->assertCount(1, $this->adminFailureJobs());
        $this->assertSame($charge->id, $this->adminFailureJobs()->first()->charge_id);

        $notification = Notification::where('user_id', $this->admin->id)->firstOrFail();
        $this->assertSame("/admin/seo-packages-subscriptions/{$subscription->id}", $notification->link);
        $this->assertStringContainsString('SEO subscription payment failed for Cathleen Siech', $notification->message);
        $this->assertStringContainsString('Next retry on Dec 4', $notification->message);
    }

    public function test_the_client_is_told_why_and_how_to_fix_it(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $this->runBilling();

        $emails = $this->clientFailureEmails();
        $this->assertCount(1, $emails);

        $mail = $emails->first()->mailable;
        $this->assertSame($this->client->email, $emails->first()->recipient_email);
        $this->assertSame('Your bank declined the payment due to insufficient funds.', $mail->failure_reason);
        $this->assertSame('December 4, 2026', $mail->next_retry_date);
        $this->assertStringContainsString('/invoices/', $mail->invoice_url);
        $this->assertStringEndsWith('/billing', $mail->billing_url);
        $this->assertStringNotContainsString('Your card has insufficient funds.', $mail->render(), 'Raw processor messages stay admin only.');
    }

    public function test_the_client_is_emailed_on_the_first_and_final_failure_only(): void
    {
        $this->makeCardFor($this->client);
        $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $sent_per_run = [];
        foreach (['2026-12-01', '2026-12-04', '2026-12-09', '2026-12-16'] as $day) {
            Carbon::setTestNow("{$day} 15:00:00");
            $before = $this->clientFailureEmails()->count();
            $this->runBilling();
            $sent_per_run[$day] = $this->clientFailureEmails()->count() - $before;
        }

        $this->assertSame(['2026-12-01' => 1, '2026-12-04' => 0, '2026-12-09' => 0, '2026-12-16' => 1], $sent_per_run);
        $this->assertCount(4, $this->adminFailureJobs(), 'Admins hear about every failed attempt.');
    }

    public function test_a_renewal_without_a_card_tells_everyone_there_is_no_card(): void
    {
        $this->makeSubscriptionFor($this->client);
        $this->mockStripe();

        $this->runBilling();

        $this->assertSame('There is no card on file for your account.', $this->clientFailureEmails()->first()->mailable->failure_reason);
        $this->assertSame('', $this->clientFailureEmails()->first()->mailable->next_retry_date);
        $this->assertCount(1, $this->adminFailureJobs());
    }

    // ── Manual charges ──────────────────────────────────────────────────────

    public function test_a_declined_manual_charge_does_not_email_anyone(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client);
        $this->declineEveryCharge();

        $this->actingAs($this->admin, 'api')
            ->postJson(self::ADMIN_URL . "/{$subscription->id}/charge")
            ->assertStatus(402)
            ->assertJsonPath('error_code', 'insufficient_funds');

        $this->assertCount(0, $this->adminFailureJobs());
        $this->assertCount(0, $this->clientFailureEmails());
        $this->assertSame(0, Notification::count());
        $this->assertSame('past_due', $subscription->refresh()->payment_status, 'The failure is still recorded.');
    }

    // ── Admin email content ─────────────────────────────────────────────────

    public function test_the_admin_failure_email_carries_the_reason_and_the_outstanding_balance(): void
    {
        $this->makeCardFor($this->client);
        $subscription = $this->makeSubscriptionFor($this->client, [
            'payment_status'    => 'past_due',
            'next_billing_date' => '2026-11-01',
        ]);
        $charge = $this->makeFailedCharge($subscription, [
            'period_start'    => '2026-11-01',
            'failure_message' => 'Your card has insufficient funds.',
            'card_label'      => 'Visa ending in 4242',
        ]);
        Carbon::setTestNow('2026-12-02 15:00:00');

        (new SendAdminSeoSubscriptionBillingNotificationJob($charge->id, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED))->handle();

        $job = Bus::dispatched(SendEmailJob::class, fn (SendEmailJob $job) => $job->recipient_email === $this->admin->email)->first();
        $mail = $job->mailable;

        $this->assertSame('Your card has insufficient funds.', $mail->failure_reason);
        $this->assertSame('2 months behind, $4,000.00 outstanding', $mail->outstanding_label);
        $this->assertSame('Printed Image', $mail->client_company);
        $this->assertStringContainsString('2 months behind, $4,000.00 outstanding', $mail->render());
    }

    public function test_a_collected_payment_email_has_no_outstanding_balance(): void
    {
        $subscription = $this->makeSubscriptionFor($this->client);
        $charge = $this->makeFailedCharge($subscription, ['status' => 'succeeded', 'paid_at' => now(), 'next_retry_at' => null]);

        (new SendAdminSeoSubscriptionBillingNotificationJob($charge->id, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_SUCCEEDED))->handle();

        $mail = Bus::dispatched(SendEmailJob::class)->first()->mailable;
        $this->assertSame('', $mail->outstanding_label);
        $this->assertSame('', $mail->failure_reason);
    }

    public function test_only_the_recipients_in_email_notification_settings_are_emailed(): void
    {
        $other_admin = $this->makeUserWithRole('admin');
        $inactive    = $this->makeUserWithRole('admin', ['is_active' => false]);
        EmailNotificationSetting::create([
            'notify_all_admins' => false,
            'enabled_user_ids'  => [$this->admin->id, $inactive->id],
            'custom_emails'     => ['billing@example.com'],
        ]);

        $subscription = $this->makeSubscriptionFor($this->client);
        $charge = $this->makeFailedCharge($subscription);

        (new SendAdminSeoSubscriptionBillingNotificationJob($charge->id, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED))->handle();

        $recipients = Bus::dispatched(SendEmailJob::class)->map(fn (SendEmailJob $job) => $job->recipient_email)->all();

        $this->assertEqualsCanonicalizing([$this->admin->email, 'billing@example.com'], $recipients);
        $this->assertNotContains($other_admin->email, $recipients);
        $this->assertNotContains($inactive->email, $recipients);
    }

    public function test_the_failure_job_does_nothing_for_a_charge_that_no_longer_exists(): void
    {
        (new SendAdminSeoSubscriptionBillingNotificationJob(999999, AdminSeoSubscriptionBillingNotification::VARIANT_PAYMENT_FAILED))->handle();

        Bus::assertNotDispatched(SendEmailJob::class);
    }
}
