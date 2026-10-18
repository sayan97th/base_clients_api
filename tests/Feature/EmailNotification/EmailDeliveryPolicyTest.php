<?php

namespace Tests\Feature\EmailNotification;

use App\Listeners\EnforceEmailDeliveryPolicyListener;
use App\Listeners\InterceptOutgoingEmailListener;
use App\Mail\AdminCreditPurchaseNotification;
use App\Mail\AdminInvoicePaidNotification;
use App\Mail\AdminOrderCommentNotification;
use App\Mail\ClientCommentReplyNotification;
use App\Mail\NotificationEmail;
use App\Mail\StaffInvitationMail;
use App\Mail\TestEmail;
use App\Models\EmailInterceptSetting;
use App\Models\EmailSuppressionLog;
use App\Models\EmailTypeSetting;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserEmailPreference;
use App\Notifications\ResetPasswordNotification;
use App\Services\EmailDeliveryService;
use App\Support\Email\EmailTypeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailDeliveryPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',      'description' => 'Client']);

        Cache::flush();
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeCommentReplyMailable(string $email): ClientCommentReplyNotification
    {
        return new ClientCommentReplyNotification(
            client_name: 'Client',
            client_email: $email,
            order_id: 'order-1',
            order_title: 'Sample Order',
            original_comment_content: 'Original comment',
            original_comment_date: 'Jan 1, 2026',
            reply_content: 'Reply',
            reply_date: 'Jan 2, 2026',
            admin_name: 'Admin Person',
            admin_initials: 'AP',
            view_reply_url: 'https://example.com',
        );
    }

    private function makeAdminCommentMailable(string $email): AdminOrderCommentNotification
    {
        return new AdminOrderCommentNotification(
            recipient_name: 'Admin',
            recipient_email: $email,
            client_name: 'Client',
            client_email: 'client@example.com',
            client_initials: 'CL',
            order_id: 'order-1',
            order_title: 'Sample Order',
            comment_content: 'A comment',
            comment_date: 'Jan 1, 2026',
            view_comment_url: 'https://example.com',
            settings_url: 'https://example.com/settings',
        );
    }

    private function makeMessage(string ...$to): Email
    {
        $message = new Email();
        $message->to(...$to);
        $message->subject('Subject');
        $message->html('<p>Body</p>');

        return $message;
    }

    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    private function mutePreference(User $user, bool $mute_all, array $disabled_email_types = []): void
    {
        UserEmailPreference::create([
            'user_id'              => $user->id,
            'mute_all_emails'      => $mute_all,
            'disabled_email_types' => $disabled_email_types,
        ]);

        EmailDeliveryService::invalidateCache();
    }

    // ─── EmailTypeCatalog ────────────────────────────────────────────────────

    /**
     * Guarantees every email in the codebase passes through the delivery
     * policy: a new Mailable or mail Notification that is not registered in
     * EmailTypeCatalog fails here instead of silently bypassing the settings.
     */
    public function test_every_mailable_and_mail_notification_is_registered_in_the_catalog(): void
    {
        $managed_classes = EmailTypeCatalog::managedClasses();
        $unregistered    = [];

        $directories = [
            app_path('Mail')          => ['App\\Mail\\', Mailable::class],
            app_path('Notifications') => ['App\\Notifications\\', BaseNotification::class],
        ];

        foreach ($directories as $directory => [$namespace, $base_class]) {
            foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
                $class = $namespace . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

                if (is_subclass_of($class, $base_class) && ! in_array($class, $managed_classes, true)) {
                    $unregistered[] = $class;
                }
            }
        }

        $this->assertSame([], $unregistered, 'Register these classes in EmailTypeCatalog: ' . implode(', ', $unregistered));
    }

    public function test_notification_email_resolves_to_the_type_of_its_portal_notification(): void
    {
        $this->assertSame('portal_payment_notification', EmailTypeCatalog::resolveKey(NotificationEmail::class, 'payment'));
        $this->assertSame('portal_order_notification', EmailTypeCatalog::resolveKey(NotificationEmail::class, 'order_comment'));
        $this->assertSame('portal_system_notification', EmailTypeCatalog::resolveKey(NotificationEmail::class, 'user_registration'));
        $this->assertSame('admin_credit_purchase', EmailTypeCatalog::resolveKey(AdminCreditPurchaseNotification::class));
        $this->assertNull(EmailTypeCatalog::resolveKey('App\\Mail\\NotRegistered'));
    }

    // ─── EmailDeliveryService::getBlockReason() ──────────────────────────────

    public function test_everything_is_delivered_when_nothing_is_configured(): void
    {
        $this->assertNull(EmailDeliveryService::getBlockReason('admin_invoice_paid', 'admin@example.com'));
    }

    public function test_globally_disabled_email_type_is_blocked_for_everyone(): void
    {
        EmailTypeSetting::create(['email_type' => 'admin_invoice_paid', 'is_enabled' => false]);

        $this->assertSame(
            EmailDeliveryService::REASON_EMAIL_TYPE_DISABLED,
            EmailDeliveryService::getBlockReason('admin_invoice_paid', 'anyone@example.com')
        );
    }

    public function test_recipient_who_muted_all_emails_is_blocked_case_insensitively(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);

        $this->assertSame(
            EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL,
            EmailDeliveryService::getBlockReason('admin_new_ticket', strtoupper($admin->email))
        );
    }

    public function test_recipient_opt_out_only_blocks_the_selected_email_types(): void
    {
        $admin = $this->makeUserWithRole('staff');
        $this->mutePreference($admin, false, ['admin_credit_purchase', 'admin_invoice_paid']);

        $this->assertSame(
            EmailDeliveryService::REASON_RECIPIENT_OPTED_OUT,
            EmailDeliveryService::getBlockReason('admin_credit_purchase', $admin->email)
        );
        $this->assertNull(EmailDeliveryService::getBlockReason('admin_new_ticket', $admin->email));
    }

    public function test_mandatory_email_types_are_never_blocked(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);
        EmailTypeSetting::create(['email_type' => 'password_reset', 'is_enabled' => false]);

        $this->assertNull(EmailDeliveryService::getBlockReason('password_reset', $admin->email));
        $this->assertNull(EmailDeliveryService::getBlockReason('staff_invitation', $admin->email));
    }

    // ─── Client side users always receive their emails ───────────────────────

    public function test_every_client_side_email_type_is_mandatory(): void
    {
        foreach (EmailTypeCatalog::all() as $email_type) {
            if ($email_type['audience'] === EmailTypeCatalog::AUDIENCE_CLIENT) {
                $this->assertTrue($email_type['is_mandatory'], "{$email_type['key']} must always be delivered");
                $this->assertNotContains($email_type['key'], EmailTypeCatalog::configurableKeys());
            }
        }
    }

    public function test_client_side_email_types_ignore_a_stale_global_toggle(): void
    {
        $client = $this->makeUserWithRole('client');
        EmailTypeSetting::create(['email_type' => 'order_report', 'is_enabled' => false]);

        $this->assertNull(EmailDeliveryService::getBlockReason('order_report', $client->email));
    }

    public function test_portal_notification_turned_off_globally_still_reaches_clients_but_not_admins(): void
    {
        $client = $this->makeUserWithRole('client');
        $admin  = $this->makeUserWithRole('admin');
        EmailTypeSetting::create(['email_type' => 'portal_payment_notification', 'is_enabled' => false]);

        $this->assertNull(EmailDeliveryService::getBlockReason('portal_payment_notification', $client->email));
        $this->assertSame(
            EmailDeliveryService::REASON_EMAIL_TYPE_DISABLED,
            EmailDeliveryService::getBlockReason('portal_payment_notification', $admin->email)
        );
    }

    public function test_a_preference_row_never_mutes_a_client_side_user(): void
    {
        $client = $this->makeUserWithRole('client');
        $this->mutePreference($client, true, ['portal_payment_notification']);

        $notification = Notification::create([
            'user_id' => $client->id,
            'type'    => 'payment',
            'message' => 'Payment received',
        ]);

        Mail::to($client->email)->send(new NotificationEmail($client, $notification));
        Mail::to($client->email)->send($this->makeCommentReplyMailable($client->email));

        $this->assertCount(2, $this->sentMessages());
        $this->assertSame(0, EmailSuppressionLog::count());
    }

    public function test_global_toggle_still_applies_to_external_admin_notification_recipients(): void
    {
        EmailTypeSetting::create(['email_type' => 'admin_invoice_paid', 'is_enabled' => false]);

        $this->assertSame(
            EmailDeliveryService::REASON_EMAIL_TYPE_DISABLED,
            EmailDeliveryService::getBlockReason('admin_invoice_paid', 'external@agency.com')
        );
    }

    public function test_client_side_email_types_cannot_be_turned_off_globally(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [['key' => 'order_report', 'is_enabled' => false]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email_types.0.key']);
    }

    public function test_preferences_cannot_be_saved_for_client_side_users(): void
    {
        $admin  = $this->makeUserWithRole('admin');
        $client = $this->makeUserWithRole('client');

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$client->id}", [
                'mute_all_emails'      => true,
                'disabled_email_types' => [],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('user_email_preferences', ['user_id' => $client->id]);
    }

    // ─── End to end through the real mailer ──────────────────────────────────

    public function test_muted_recipient_does_not_receive_the_email_and_it_is_logged(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);

        Mail::to($admin->email)->send($this->makeAdminCommentMailable($admin->email));

        $this->assertCount(0, $this->sentMessages());
        $this->assertDatabaseHas('email_suppression_logs', [
            'mailable_class'  => AdminOrderCommentNotification::class,
            'email_type'      => 'admin_order_comment',
            'recipient_email' => $admin->email,
            'reason'          => EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL,
        ]);
    }

    public function test_recipient_without_restrictions_still_receives_the_email(): void
    {
        $client = $this->makeUserWithRole('client');

        Mail::to($client->email)->send($this->makeCommentReplyMailable($client->email));

        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(0, EmailSuppressionLog::count());
    }

    public function test_test_emails_ignore_the_delivery_policy(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);

        Mail::to($admin->email)->send(new TestEmail('Admin'));

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_password_reset_notification_is_delivered_to_a_muted_user(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);

        $admin->notify(new ResetPasswordNotification('token'));

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_portal_notification_email_is_blocked_by_its_resolved_type(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, false, ['portal_payment_notification']);

        $notification = Notification::create([
            'user_id' => $admin->id,
            'type'    => 'payment',
            'message' => 'Client paid invoice INV-1',
        ]);

        Mail::to($admin->email)->send(new NotificationEmail($admin, $notification));

        $this->assertCount(0, $this->sentMessages());
        $this->assertDatabaseHas('email_suppression_logs', [
            'email_type' => 'portal_payment_notification',
            'reason'     => EmailDeliveryService::REASON_RECIPIENT_OPTED_OUT,
        ]);
    }

    // ─── EnforceEmailDeliveryPolicyListener ──────────────────────────────────

    public function test_listener_removes_only_blocked_recipients_from_a_multi_recipient_message(): void
    {
        $muted   = $this->makeUserWithRole('admin');
        $allowed = $this->makeUserWithRole('admin');
        $this->mutePreference($muted, true);

        $message = $this->makeMessage($muted->email, $allowed->email);
        $event   = new MessageSending($message, ['__laravel_mailable' => AdminInvoicePaidNotification::class]);

        $result = (new EnforceEmailDeliveryPolicyListener())->handle($event);

        $this->assertNull($result);
        $this->assertSame([$allowed->email], array_map(fn ($address) => $address->getAddress(), $message->getTo()));
    }

    public function test_listener_cancels_the_send_when_every_recipient_is_blocked(): void
    {
        EmailTypeSetting::create(['email_type' => 'admin_invoice_paid', 'is_enabled' => false]);

        $event = new MessageSending(
            $this->makeMessage('a@example.com', 'b@example.com'),
            ['__laravel_mailable' => AdminInvoicePaidNotification::class]
        );

        $this->assertFalse((new EnforceEmailDeliveryPolicyListener())->handle($event));
        $this->assertSame(2, EmailSuppressionLog::count());
    }

    public function test_listener_lets_unregistered_mailables_through(): void
    {
        $event = new MessageSending($this->makeMessage('a@example.com'), ['__laravel_mailable' => 'App\\Mail\\Unknown']);

        $this->assertNull((new EnforceEmailDeliveryPolicyListener())->handle($event));
    }

    public function test_interceptor_does_not_copy_an_email_that_the_policy_blocks(): void
    {
        Bus::fake();

        $admin = $this->makeUserWithRole('admin');
        $this->mutePreference($admin, true);

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => true,
            'intercept_client_emails' => true,
            'recipient_emails'        => ['auditor@agency.com'],
        ]);

        $event = new MessageSending(
            $this->makeMessage($admin->email),
            ['__laravel_mailable' => AdminInvoicePaidNotification::class]
        );

        (new InterceptOutgoingEmailListener())->handle($event);

        Bus::assertNothingDispatched();
    }

    // ─── EmailTypeSettingController ──────────────────────────────────────────

    public function test_admin_can_list_and_disable_email_types(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->getJson('/api/admin/email-type-settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'admin_invoice_paid', 'is_enabled' => true])
            ->assertJsonFragment(['key' => 'password_reset', 'is_mandatory' => true]);

        $this->actingAs($admin)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [['key' => 'admin_invoice_paid', 'is_enabled' => false]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('email_type_settings', ['email_type' => 'admin_invoice_paid', 'is_enabled' => false]);
        $this->assertFalse(EmailDeliveryService::isEmailTypeEnabled('admin_invoice_paid'));
    }

    public function test_mandatory_email_types_cannot_be_disabled(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [['key' => 'password_reset', 'is_enabled' => false]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email_types.0.key']);
    }

    public function test_staff_can_view_but_not_change_global_email_types(): void
    {
        $staff = $this->makeUserWithRole('staff');

        $this->actingAs($staff)->getJson('/api/admin/email-type-settings')->assertOk();
        $this->actingAs($staff)
            ->putJson('/api/admin/email-type-settings', [
                'email_types' => [['key' => 'admin_invoice_paid', 'is_enabled' => false]],
            ])
            ->assertStatus(403);
    }

    public function test_suppression_logs_endpoint_returns_recent_entries(): void
    {
        $admin = $this->makeUserWithRole('admin');

        EmailDeliveryService::logSuppression(
            mailable_class:  AdminInvoicePaidNotification::class,
            email_type:      'admin_invoice_paid',
            recipient_email: 'muted@example.com',
            subject:         'Payment Received',
            reason:          EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL,
        );

        $this->actingAs($admin)
            ->getJson('/api/admin/email-type-settings/suppression-logs')
            ->assertOk()
            ->assertJsonPath('logs.0.recipient_email', 'muted@example.com')
            ->assertJsonPath('logs.0.reason', EmailDeliveryService::REASON_RECIPIENT_MUTED_ALL);
    }

    // ─── UserEmailPreferenceController ───────────────────────────────────────

    public function test_index_lists_admin_side_users_with_admin_facing_email_types_only(): void
    {
        $admin  = $this->makeUserWithRole('admin');
        $client = $this->makeUserWithRole('client');

        $response = $this->actingAs($admin)->getJson('/api/admin/user-email-preferences')->assertOk();

        $user_ids        = array_column($response->json('users'), 'id');
        $email_type_keys = array_column($response->json('email_types'), 'key');

        $this->assertContains($admin->id, $user_ids);
        $this->assertNotContains($client->id, $user_ids);
        $this->assertContains('admin_credit_purchase', $email_type_keys);
        $this->assertNotContains('order_report', $email_type_keys);
    }

    public function test_admin_can_update_another_users_preferences(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $staff = $this->makeUserWithRole('staff');

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$staff->id}", [
                'mute_all_emails'      => false,
                'disabled_email_types' => ['admin_credit_purchase', 'admin_invoice_paid'],
            ])
            ->assertOk()
            ->assertJsonPath('preference.disabled_email_types', ['admin_credit_purchase', 'admin_invoice_paid']);

        $this->assertSame(
            EmailDeliveryService::REASON_RECIPIENT_OPTED_OUT,
            EmailDeliveryService::getBlockReason('admin_invoice_paid', $staff->email)
        );
        $this->assertDatabaseHas('user_email_preferences', [
            'user_id'            => $staff->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_staff_can_update_only_their_own_preferences(): void
    {
        $staff       = $this->makeUserWithRole('staff');
        $other_staff = $this->makeUserWithRole('staff');
        $payload     = ['mute_all_emails' => true, 'disabled_email_types' => []];

        $this->actingAs($staff)->putJson("/api/admin/user-email-preferences/{$staff->id}", $payload)->assertOk();
        $this->actingAs($staff)->putJson("/api/admin/user-email-preferences/{$other_staff->id}", $payload)->assertStatus(403);
    }

    public function test_preferences_reject_unknown_or_mandatory_email_types(): void
    {
        $admin = $this->makeUserWithRole('admin');

        $this->actingAs($admin)
            ->putJson("/api/admin/user-email-preferences/{$admin->id}", [
                'mute_all_emails'      => false,
                'disabled_email_types' => ['password_reset', 'does_not_exist'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['disabled_email_types.0', 'disabled_email_types.1']);
    }

    public function test_client_users_cannot_access_email_preferences(): void
    {
        $client = $this->makeUserWithRole('client');

        $this->actingAs($client)->getJson('/api/admin/user-email-preferences')->assertStatus(403);
        $this->actingAs($client)->getJson('/api/admin/email-type-settings')->assertStatus(403);
    }
}
