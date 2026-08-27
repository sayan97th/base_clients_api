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
            config('app.frontend_url') . '/orders/order-uuid-1',
            $mail->content()->with['action_url']
        );
    }

    public function test_absolute_link_is_used_as_is(): void
    {
        $notification = Notification::create([
            'user_id' => $this->client->id,
            'type'    => 'system',
            'message' => 'Check out this external resource.',
            'link'    => 'https://example.com/resource',
        ]);

        $mail = new NotificationEmail($this->client, $notification);

        $this->assertSame('https://example.com/resource', $mail->content()->with['action_url']);
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
            config('app.frontend_url') . '/notifications',
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
