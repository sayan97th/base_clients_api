<?php

namespace Tests\Feature\EmailNotification;

use App\Listeners\InterceptOutgoingEmailListener;
use App\Mail\ClientCommentReplyNotification;
use App\Models\EmailInterceptLog;
use App\Models\EmailInterceptSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailInterceptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailInterceptSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'],      ['display_name' => 'Admin',      'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',      'description' => 'Staff']);
        Role::firstOrCreate(['name' => 'client'],      ['display_name' => 'Client',     'description' => 'Client']);

        Cache::flush();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeClient(): User
    {
        $client = User::factory()->create(['is_active' => true]);
        $client->assignRole('client');

        return $client;
    }

    // ─── EmailInterceptService::resolveAudience() ────────────────────────────

    public function test_resolve_audience_returns_admin_for_admin_side_users(): void
    {
        $admin = $this->makeAdmin();

        $this->assertSame(EmailInterceptService::AUDIENCE_ADMIN, EmailInterceptService::resolveAudience($admin->email));
    }

    public function test_resolve_audience_returns_client_for_client_side_users(): void
    {
        $client = $this->makeClient();

        $this->assertSame(EmailInterceptService::AUDIENCE_CLIENT, EmailInterceptService::resolveAudience($client->email));
    }

    public function test_resolve_audience_defaults_to_client_for_unknown_addresses(): void
    {
        $this->assertSame(
            EmailInterceptService::AUDIENCE_CLIENT,
            EmailInterceptService::resolveAudience('not-a-user@example.com')
        );
    }

    // ─── EmailInterceptService::getBccRecipients() ───────────────────────────

    public function test_get_bcc_recipients_is_empty_when_no_settings_exist(): void
    {
        $this->assertSame([], EmailInterceptService::getBccRecipients('client@example.com'));
    }

    public function test_get_bcc_recipients_is_empty_when_audience_toggle_is_off(): void
    {
        $client = $this->makeClient();

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => true,
            'intercept_client_emails' => false,
            'recipient_emails'        => ['auditor@agency.com'],
        ]);

        $this->assertSame([], EmailInterceptService::getBccRecipients($client->email));
    }

    public function test_get_bcc_recipients_returns_configured_addresses_when_enabled(): void
    {
        $admin = $this->makeAdmin();

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => true,
            'intercept_client_emails' => false,
            'recipient_emails'        => ['auditor@agency.com', 'lead@agency.com'],
        ]);

        $recipients = EmailInterceptService::getBccRecipients($admin->email);

        $this->assertContains('auditor@agency.com', $recipients);
        $this->assertContains('lead@agency.com', $recipients);
    }

    public function test_get_bcc_recipients_excludes_the_original_recipient(): void
    {
        $admin = $this->makeAdmin();

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => true,
            'intercept_client_emails' => false,
            'recipient_emails'        => [$admin->email, 'auditor@agency.com'],
        ]);

        $recipients = EmailInterceptService::getBccRecipients($admin->email);

        $this->assertNotContains($admin->email, $recipients);
        $this->assertContains('auditor@agency.com', $recipients);
    }

    // ─── InterceptOutgoingEmailListener ──────────────────────────────────────

    public function test_listener_adds_bcc_and_logs_when_interception_is_enabled(): void
    {
        $client = $this->makeClient();

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => false,
            'intercept_client_emails' => true,
            'recipient_emails'        => ['auditor@agency.com'],
        ]);

        $mailable = new ClientCommentReplyNotification(
            client_name: $client->first_name ?? 'Client',
            client_email: $client->email,
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

        $symfony_message = new Email();
        $symfony_message->to($client->email);
        $symfony_message->subject('Reply to your comment');

        $event = new MessageSending($symfony_message, ['__laravel_mailable' => get_class($mailable)]);

        (new InterceptOutgoingEmailListener())->handle($event);

        $bcc_addresses = array_map(fn ($a) => $a->getAddress(), $symfony_message->getBcc());
        $this->assertContains('auditor@agency.com', $bcc_addresses);

        $this->assertDatabaseHas('email_intercept_logs', [
            'mailable_class'           => get_class($mailable),
            'audience'                 => EmailInterceptService::AUDIENCE_CLIENT,
            'original_recipient_email' => $client->email,
        ]);
    }

    public function test_listener_does_nothing_when_interception_is_disabled(): void
    {
        $client = $this->makeClient();

        EmailInterceptSetting::create([
            'intercept_admin_emails'  => false,
            'intercept_client_emails' => false,
            'recipient_emails'        => ['auditor@agency.com'],
        ]);

        $symfony_message = new Email();
        $symfony_message->to($client->email);
        $symfony_message->subject('Some notification');

        $event = new MessageSending($symfony_message, ['__laravel_mailable' => 'App\\Mail\\SomeMail']);

        (new InterceptOutgoingEmailListener())->handle($event);

        $this->assertEmpty($symfony_message->getBcc());
        $this->assertSame(0, EmailInterceptLog::count());
    }

    // ─── EmailInterceptSettingController ─────────────────────────────────────

    public function test_admin_can_view_and_update_intercept_settings(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/email-intercept-settings')
            ->assertOk()
            ->assertJson([
                'intercept_admin_emails'  => false,
                'intercept_client_emails' => false,
                'recipient_emails'        => [],
            ]);

        $this->actingAs($admin)
            ->putJson('/api/admin/email-intercept-settings', [
                'intercept_admin_emails'  => true,
                'intercept_client_emails' => true,
                'recipient_emails'        => ['auditor@agency.com'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('email_intercept_settings', [
            'intercept_admin_emails'  => true,
            'intercept_client_emails' => true,
        ]);
    }

    public function test_update_requires_at_least_one_recipient_when_enabling_interception(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->putJson('/api/admin/email-intercept-settings', [
                'intercept_admin_emails'  => true,
                'intercept_client_emails' => false,
                'recipient_emails'        => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_emails']);
    }

    public function test_client_users_cannot_access_intercept_settings(): void
    {
        $client = $this->makeClient();

        $this->actingAs($client)
            ->getJson('/api/admin/email-intercept-settings')
            ->assertStatus(403);
    }

    public function test_logs_endpoint_returns_recent_intercepted_copies(): void
    {
        $admin = $this->makeAdmin();

        EmailInterceptLog::create([
            'mailable_class'            => 'App\\Mail\\OrderStatusChangeMail',
            'audience'                  => EmailInterceptService::AUDIENCE_CLIENT,
            'original_recipient_email'  => 'client@example.com',
            'subject'                   => 'Order Status Update',
            'copied_to_emails'          => ['auditor@agency.com'],
            'intercepted_at'            => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/email-intercept-settings/logs')
            ->assertOk();

        $this->assertCount(1, $response->json('logs'));
    }
}
