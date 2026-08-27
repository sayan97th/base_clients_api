<?php

namespace Tests\Feature\Mail;

use App\Mail\NotificationEmail;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin user']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_admin_link_resolves_against_the_admin_portal_domain(): void
    {
        $notification = Notification::create([
            'user_id' => $this->admin->id,
            'type'    => 'invoice',
            'message' => 'Invoice INV-1 has been created for a client.',
            'link'    => '/admin/invoices/123',
        ]);

        $mail = new NotificationEmail($this->admin, $notification);

        $this->assertSame(
            rtrim(config('app.admin_url'), '/') . '/admin/invoices/123',
            $mail->content()->with['action_url']
        );
    }

    /**
     * Client-portal action URLs are tagged with "notification_id" so that, if an
     * admin/staff account ever opens this same link while signed in on the admin
     * side, the frontend can route them through the impersonation gate
     * (NotificationRedirectController) instead of silently bouncing them away.
     */
    public function test_client_link_resolves_against_the_client_portal_domain(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'order',
            'message' => 'Your order has been updated.',
            'link'    => '/orders/order-uuid-1',
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame(
            config('app.frontend_url') . '/orders/order-uuid-1?notification_id=' . $notification->id,
            $mail->content()->with['action_url']
        );
    }

    /**
     * Admin-portal links are never tagged: they can never legitimately be reached
     * through the client-side impersonation gate, so there is nothing to resolve.
     */
    public function test_admin_link_is_not_tagged_with_a_notification_id(): void
    {
        $notification = Notification::create([
            'user_id' => $this->admin->id,
            'type'    => 'invoice',
            'message' => 'Invoice INV-2 has been created for a client.',
            'link'    => '/admin/invoices/456',
        ]);

        $mail = new NotificationEmail($this->admin, $notification);

        $this->assertSame(
            rtrim(config('app.admin_url'), '/') . '/admin/invoices/456',
            $mail->content()->with['action_url']
        );
    }

    /**
     * An absolute link stored on a notification must resolve to one of our own
     * portal domains. Without this check a corrupted or attacker-influenced `link`
     * value would turn the "View Invoice" button into an open redirect to any
     * external host.
     */
    public function test_absolute_link_to_an_untrusted_host_falls_back_to_a_safe_default(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'system',
            'message' => 'Check out this external resource.',
            'link'    => 'https://evil-external-host.example/resource',
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame(
            config('app.frontend_url') . '/notifications?notification_id=' . $notification->id,
            $mail->content()->with['action_url']
        );
    }

    public function test_absolute_link_to_our_own_frontend_domain_is_used_as_is(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'system',
            'message' => 'Check out this resource on our own portal.',
            'link'    => rtrim(config('app.frontend_url'), '/') . '/resources/guide',
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame(
            rtrim(config('app.frontend_url'), '/') . '/resources/guide?notification_id=' . $notification->id,
            $mail->content()->with['action_url']
        );
    }

    public function test_missing_link_falls_back_to_the_frontend_notifications_page(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'system',
            'message' => 'A system notification with no target.',
            'link'    => null,
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame(
            config('app.frontend_url') . '/notifications?notification_id=' . $notification->id,
            $mail->content()->with['action_url']
        );
    }

    public function test_link_with_a_protocol_relative_prefix_is_rejected(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'system',
            'message' => 'A notification with an unsafe link.',
            'link'    => '//evil-external-host.example/phishing',
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame(
            config('app.frontend_url') . '/notifications?notification_id=' . $notification->id,
            $mail->content()->with['action_url']
        );
    }

    public function test_invoice_email_shows_a_precise_view_invoice_call_to_action(): void
    {
        $notification = Notification::create([
            'user_id' => $this->admin->id,
            'type'    => 'invoice',
            'message' => 'Invoice INV-1 has been created for a client.',
            'link'    => '/admin/invoices/123',
        ]);

        $mail = new NotificationEmail($this->admin, $notification);
        $html = $mail->render();

        $this->assertStringContainsString('View Invoice', $html);
    }
}
