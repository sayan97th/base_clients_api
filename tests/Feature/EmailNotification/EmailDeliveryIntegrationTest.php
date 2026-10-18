<?php

namespace Tests\Feature\EmailNotification;

use App\Jobs\SendEmailJob;
use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Models\Notification;
use App\Services\EmailDeliveryService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\EmailNotification\Concerns\InteractsWithEmailDelivery;
use Tests\TestCase;

/**
 * Exercises the delivery policy through the real send paths used across the
 * application (queued jobs, queued mailables, the portal notification
 * service), with the "array" mailer standing in for the SMTP provider.
 */
class EmailDeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase, InteractsWithEmailDelivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEmailDelivery();
    }

    public function test_send_email_job_respects_a_recipient_opt_out(): void
    {
        $muted   = $this->makeUserWithRole('admin');
        $allowed = $this->makeUserWithRole('admin');
        $this->setEmailPreference($muted, false, ['admin_order_comment']);

        SendEmailJob::dispatch($this->makeAdminCommentMailable($muted->email), $muted->email);
        SendEmailJob::dispatch($this->makeAdminCommentMailable($allowed->email), $allowed->email);

        $this->assertSame([$allowed->email], $this->sentRecipientEmails());
    }

    public function test_queued_mailable_is_checked_when_it_is_actually_sent(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->setEmailPreference($admin, true);

        Mail::to($admin->email)->queue($this->makeAdminCommentMailable($admin->email));

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(1, EmailSuppressionLog::count());
    }

    public function test_a_global_toggle_stops_the_email_for_every_admin_side_recipient(): void
    {
        $admins = [$this->makeUserWithRole('super_admin'), $this->makeUserWithRole('admin'), $this->makeUserWithRole('staff')];
        EmailTypeSetting::create(['email_type' => 'admin_order_comment', 'is_enabled' => false]);

        foreach ($admins as $admin) {
            Mail::to($admin->email)->send($this->makeAdminCommentMailable($admin->email));
        }

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(3, EmailSuppressionLog::where('reason', EmailDeliveryService::REASON_EMAIL_TYPE_DISABLED)->count());
    }

    public function test_one_admins_preference_never_affects_another_admin(): void
    {
        $kennedy = $this->makeUserWithRole('staff');
        $abby    = $this->makeUserWithRole('admin');
        $this->setEmailPreference($kennedy, true);

        Mail::to($kennedy->email)->send($this->makeAdminCommentMailable($kennedy->email));
        Mail::to($abby->email)->send($this->makeAdminCommentMailable($abby->email));

        $this->assertSame([$abby->email], $this->sentRecipientEmails());
    }

    public function test_muting_email_keeps_the_in_portal_notification(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->setEmailPreference($admin, true);

        app(NotificationService::class)->createNotification($admin, 'payment', 'Client paid invoice INV-1');

        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
        $this->assertSame([], $this->sentMessages());
        $this->assertDatabaseHas('email_suppression_logs', [
            'recipient_email' => $admin->email,
            'email_type'      => 'portal_payment_notification',
        ]);
    }

    public function test_client_receives_the_portal_notification_email_even_when_admins_turned_it_off(): void
    {
        $client = $this->makeUserWithRole('client');
        $admin  = $this->makeUserWithRole('admin');
        EmailTypeSetting::create(['email_type' => 'portal_payment_notification', 'is_enabled' => false]);

        $notification_service = app(NotificationService::class);
        $notification_service->createNotification($client, 'payment', 'Your payment was received');
        $notification_service->createNotification($admin, 'payment', 'Client paid invoice INV-1');

        $this->assertSame([$client->email], $this->sentRecipientEmails());
    }

    public function test_settings_changed_through_the_api_apply_to_the_very_next_email(): void
    {
        $admin = $this->makeUserWithRole('admin');

        // Warm the policy caches with the current "everything enabled" state.
        $this->assertTrue(EmailDeliveryService::isDeliveryAllowed('admin_order_comment', $admin->email));

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$admin->id}", [
                'mute_all_emails'      => true,
                'disabled_email_types' => [],
            ])
            ->assertOk();

        Mail::to($admin->email)->send($this->makeAdminCommentMailable($admin->email));
        $this->assertSame([], $this->sentMessages());

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$admin->id}", [
                'mute_all_emails'      => false,
                'disabled_email_types' => [],
            ])
            ->assertOk();

        Mail::to($admin->email)->send($this->makeAdminCommentMailable($admin->email));
        $this->assertSame([$admin->email], $this->sentRecipientEmails());
    }

    public function test_admin_side_detection_is_case_insensitive_and_covers_mixed_roles(): void
    {
        $this->makeUserWithRole('admin', ['email' => 'Kennedy@Example.com']);
        $mixed = $this->makeUserWithRole('client');
        $mixed->assignRole('staff');
        EmailDeliveryService::invalidateCache();

        $this->assertTrue(EmailDeliveryService::isAdminSideRecipient('kennedy@example.com'));
        $this->assertTrue(EmailDeliveryService::isAdminSideRecipient($mixed->email));
        $this->assertFalse(EmailDeliveryService::isAdminSideRecipient('stranger@example.com'));
    }

    public function test_suppression_log_is_pruned_to_the_retention_limit(): void
    {
        $rows = [];
        for ($i = 0; $i < EmailSuppressionLog::MAX_RETAINED_ROWS + 10; $i++) {
            $rows[] = [
                'mailable_class'  => 'App\\Mail\\AdminInvoicePaidNotification',
                'email_type'      => 'admin_invoice_paid',
                'recipient_email' => "user{$i}@example.com",
                'subject'         => null,
                'reason'          => EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL,
                'suppressed_at'   => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }
        EmailSuppressionLog::insert($rows);

        EmailSuppressionLog::pruneOldEntries();

        $this->assertSame(EmailSuppressionLog::MAX_RETAINED_ROWS, EmailSuppressionLog::count());
        $this->assertDatabaseMissing('email_suppression_logs', ['recipient_email' => 'user0@example.com']);
        $this->assertDatabaseHas('email_suppression_logs', ['recipient_email' => 'user509@example.com']);
    }
}
