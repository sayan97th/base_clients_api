<?php

namespace Tests\Feature\Notification;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceFilterTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->service = app(NotificationService::class);
        $this->user    = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('client');
    }

    // ─── createNotification persists deep-link fields ──────────────────────────

    public function test_create_notification_persists_resource_type_resource_id_and_metadata(): void
    {
        $notification = $this->service->createNotification(
            $this->user,
            'order_comment',
            'Someone posted a comment on an order.',
            [
                'link'          => '/orders/abc?comment_id=5#comment-5',
                'resource_type' => 'order_comment',
                'resource_id'   => '5',
                'metadata'      => ['comment_id' => 5, 'order_id' => 'abc'],
                'mail_data'     => ['skip_email' => true],
            ]
        );

        $notification->refresh();

        $this->assertSame('order_comment', $notification->resource_type);
        $this->assertSame('5', $notification->resource_id);
        $this->assertSame(['comment_id' => 5, 'order_id' => 'abc'], $notification->metadata);
        $this->assertSame('/orders/abc?comment_id=5#comment-5', $notification->link);
    }

    public function test_create_notification_allows_null_deep_link_fields(): void
    {
        $notification = $this->service->createNotification(
            $this->user,
            'system',
            'A system notification with no deep link.',
            ['mail_data' => ['skip_email' => true]]
        );

        $notification->refresh();

        $this->assertNull($notification->resource_type);
        $this->assertNull($notification->resource_id);
        $this->assertNull($notification->metadata);
    }

    // ─── Type-filter grouping ───────────────────────────────────────────────────

    public function test_filtering_client_notifications_by_order_type_also_returns_order_comment_notifications(): void
    {
        $this->service->createNotification($this->user, 'order', 'Order updated.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->user, 'order_comment', 'A comment was posted.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->user, 'payment', 'Payment received.', ['mail_data' => ['skip_email' => true]]);

        $results = $this->service->getAllNotifications($this->user, ['type' => 'order']);

        $this->assertCount(2, $results);
        $this->assertEqualsCanonicalizing(
            ['order', 'order_comment'],
            $results->pluck('type')->all()
        );
    }

    public function test_filtering_admin_notifications_by_order_type_also_returns_order_comment_notifications(): void
    {
        Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->service->createNotification($admin, 'order', 'Order updated.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($admin, 'order_comment', 'A comment was posted.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($admin, 'system', 'System alert.', ['mail_data' => ['skip_email' => true]]);

        $paginated = $this->service->getAdminNotifications(['type' => 'order'], 15);

        $this->assertSame(2, $paginated->total());
        $this->assertEqualsCanonicalizing(
            ['order', 'order_comment'],
            collect($paginated->items())->pluck('type')->all()
        );
    }

    public function test_filtering_by_an_ungrouped_type_still_matches_only_that_exact_type(): void
    {
        $this->service->createNotification($this->user, 'payment', 'Payment received.', ['mail_data' => ['skip_email' => true]]);
        $this->service->createNotification($this->user, 'system', 'System alert.', ['mail_data' => ['skip_email' => true]]);

        $results = $this->service->getAllNotifications($this->user, ['type' => 'payment']);

        $this->assertCount(1, $results);
        $this->assertSame('payment', $results->first()->type);
    }
}
