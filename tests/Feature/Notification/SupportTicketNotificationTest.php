<?php

namespace Tests\Feature\Notification;

use App\Jobs\SendAdminNewTicketNotificationJob;
use App\Jobs\SendAdminTicketMessageNotificationJob;
use App\Jobs\SendClientTicketReplyNotificationJob;
use App\Models\Notification;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SupportTicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_creating_a_ticket_notifies_every_configured_admin(): void
    {
        Bus::fake();
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $response = $this->actingAs($this->client, 'api')->postJson('/api/support-tickets', [
            'subject' => 'Cannot access my invoice',
            'content' => 'The invoice PDF link is returning a 404.',
        ]);

        $response->assertCreated();
        $ticket_id = $response->json('support_ticket.id');

        foreach ([$this->admin, $other_admin] as $admin_user) {
            $notification = Notification::where('user_id', $admin_user->id)
                ->where('type', 'ticket')
                ->firstOrFail();

            $this->assertSame("/admin/support-tickets/{$ticket_id}", $notification->link);
            $this->assertSame('support_ticket', $notification->resource_type);
            $this->assertSame((string) $ticket_id, $notification->resource_id);
        }

        Bus::assertDispatched(SendAdminNewTicketNotificationJob::class);
    }

    public function test_client_reply_notifies_admins_and_admin_reply_notifies_the_client(): void
    {
        Bus::fake();

        $ticket = SupportTicket::create([
            'subject' => 'Billing question',
            'priority' => 'medium',
            'user_id' => $this->client->id,
        ]);
        $ticket->messages()->create([
            'sender_id' => $this->client->id,
            'content' => 'Initial message.',
        ]);

        $this->actingAs($this->client, 'api')
            ->postJson("/api/support-tickets/{$ticket->id}/messages", ['content' => 'Any update on this?'])
            ->assertCreated();

        $this->assertNotNull(
            Notification::where('user_id', $this->admin->id)->where('type', 'ticket')->first()
        );
        Bus::assertDispatched(SendAdminTicketMessageNotificationJob::class);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/support-tickets/{$ticket->id}/messages", ['content' => 'Looking into it now.'])
            ->assertCreated();

        $client_notification = Notification::where('user_id', $this->client->id)
            ->where('type', 'ticket')
            ->firstOrFail();

        $this->assertSame("/support/{$ticket->id}", $client_notification->link);
        Bus::assertDispatched(SendClientTicketReplyNotificationJob::class);
    }

    public function test_a_staff_members_own_reply_does_not_notify_the_rest_of_the_admin_team(): void
    {
        Bus::fake();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super Admin']);
        $super_admin = User::factory()->create(['is_active' => true]);
        $super_admin->assignRole('super_admin');

        $ticket = SupportTicket::create([
            'subject' => 'Escalation',
            'priority' => 'high',
            'user_id' => $this->client->id,
        ]);
        $ticket->messages()->create([
            'sender_id' => $this->client->id,
            'content' => 'Initial message.',
        ]);

        $this->actingAs($super_admin, 'api')
            ->postJson("/api/support-tickets/{$ticket->id}/messages", ['content' => 'Acting on behalf of the client account.'])
            ->assertCreated();

        $this->assertSame(
            0,
            Notification::where('type', 'ticket')->where('user_id', '!=', $this->client->id)->count()
        );
    }
}
