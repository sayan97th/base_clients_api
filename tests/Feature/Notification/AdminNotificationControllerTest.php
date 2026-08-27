<?php

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->service = app(NotificationService::class);
    }

    public function test_index_exposes_resource_type_resource_id_and_metadata_for_deep_linking(): void
    {
        $this->service->createNotification(
            $this->admin,
            'order_comment',
            'A client posted a comment on an order.',
            [
                'link'          => '/admin/orders/order-1?comment_id=9#comment-9',
                'resource_type' => 'order_comment',
                'resource_id'   => '9',
                'metadata'      => ['comment_id' => 9, 'order_id' => 'order-1'],
                'mail_data'     => ['skip_email' => true],
            ]
        );

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/notifications')
            ->assertOk();

        $notification = collect($response->json('data'))->firstOrFail();

        $this->assertSame('order_comment', $notification['resource_type']);
        $this->assertSame('9', $notification['resource_id']);
        $this->assertSame(['comment_id' => 9, 'order_id' => 'order-1'], $notification['metadata']);
        $this->assertSame('/admin/orders/order-1?comment_id=9#comment-9', $notification['link']);
    }

    public function test_filtering_by_order_type_via_the_endpoint_also_returns_order_comment_notifications(): void
    {
        $this->service->createNotification($this->admin, 'order', 'Order updated.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->admin, 'order_comment', 'Comment posted.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->admin, 'payment', 'Payment received.', ['mail_data' => ['skip_email' => true]]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/notifications?type=order')
            ->assertOk();

        $types = collect($response->json('data'))->pluck('type')->all();

        $this->assertEqualsCanonicalizing(['order', 'order_comment'], $types);
    }

    public function test_client_role_cannot_access_admin_notifications(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/admin/notifications')
            ->assertForbidden();
    }

    /**
     * A client's own notifications (e.g. their copy of an order_comment reply, or an
     * order-placed confirmation) carry client-portal links with no "/admin" prefix.
     * If these leak into the admin feed, a staff user clicking one gets bounced to
     * /admin/dashboard by the app's route guard, which is exactly the bug this test
     * guards against: the admin feed must only ever surface admin/staff-owned rows.
     */
    public function test_a_clients_own_notification_does_not_appear_in_the_admin_feed(): void
    {
        $this->service->createNotification(
            $this->client,
            'order_comment',
            'A staff member replied to your order discussion.',
            [
                'link'      => '/orders/order-1?comment_id=9#comment-9',
                'mail_data' => ['skip_email' => true],
            ]
        );
        $this->service->createNotification(
            $this->admin,
            'order_comment',
            'A client posted a comment on an order.',
            [
                'link'      => '/admin/orders/order-1?comment_id=9#comment-9',
                'mail_data' => ['skip_email' => true],
            ]
        );

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/notifications')
            ->assertOk();

        $messages = collect($response->json('data'))->pluck('message')->all();

        $this->assertSame(['A client posted a comment on an order.'], $messages);
    }

    public function test_admin_unread_count_excludes_a_clients_own_notifications(): void
    {
        $this->service->createNotification(
            $this->client,
            'order',
            'Your order has been placed successfully.',
            ['mail_data' => ['skip_email' => true]]
        );

        $count = $this->service->getAdminUnreadCount($this->admin);

        $this->assertSame(0, $count);
    }

    public function test_type_filter_rejects_an_unknown_type_value(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/notifications?type=not_a_real_type')
            ->assertStatus(422);
    }

    /**
     * The reported bug: the admin feed queried every admin/staff-owned row instead of
     * scoping to the authenticated admin, so one admin saw every other admin's
     * notifications (e.g. every order-comment reply fanned out to the whole team).
     */
    public function test_an_admins_feed_excludes_another_admins_notifications(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $this->service->createNotification(
            $other_admin,
            'order_comment',
            'A client posted a comment on an order.',
            ['mail_data' => ['skip_email' => true]]
        );
        $this->service->createNotification(
            $this->admin,
            'order_comment',
            'A different client posted a comment on an order.',
            ['mail_data' => ['skip_email' => true]]
        );

        $response = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/notifications')
            ->assertOk();

        $messages = collect($response->json('data'))->pluck('message')->all();

        $this->assertSame(['A different client posted a comment on an order.'], $messages);
    }

    public function test_an_admins_unread_count_excludes_another_admins_notifications(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $this->service->createNotification(
            $other_admin,
            'system',
            'Unread for the other admin.',
            ['mail_data' => ['skip_email' => true]]
        );

        $count = $this->service->getAdminUnreadCount($this->admin);

        $this->assertSame(0, $count);
    }

    public function test_mark_all_as_read_only_affects_the_authenticated_admins_notifications(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $mine = $this->service->createNotification($this->admin, 'system', 'Mine.', ['mail_data' => ['skip_email' => true]]);
        $theirs = $this->service->createNotification($other_admin, 'system', 'Theirs.', ['mail_data' => ['skip_email' => true]]);

        $response = $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/notifications/read-all')
            ->assertOk();

        $this->assertSame(1, $response->json('data.updated_count'));
        $this->assertTrue($mine->fresh()->is_read);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_admin_cannot_mark_another_admins_notification_as_read(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $notification = $this->service->createNotification(
            $other_admin,
            'system',
            'Belongs to the other admin.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_read);
    }

    public function test_admin_cannot_archive_another_admins_notification(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $notification = $this->service->createNotification(
            $other_admin,
            'system',
            'Belongs to the other admin.',
            ['mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/notifications/{$notification->id}/archive")
            ->assertForbidden();

        $this->assertFalse($notification->fresh()->is_archived);
    }

    public function test_admin_cannot_unarchive_another_admins_notification(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $notification = $this->service->createNotification(
            $other_admin,
            'system',
            'Belongs to the other admin.',
            ['mail_data' => ['skip_email' => true]]
        );
        $notification->archive();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/notifications/{$notification->id}/unarchive")
            ->assertForbidden();

        $this->assertTrue($notification->fresh()->is_archived);
    }
}
