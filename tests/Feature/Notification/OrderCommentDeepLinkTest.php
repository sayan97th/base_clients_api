<?php

namespace Tests\Feature\Notification;

use App\Jobs\SendAdminCommentNotificationJob;
use App\Jobs\SendClientCommentReplyNotificationJob;
use App\Models\LinkBuildingOrder;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class OrderCommentDeepLinkTest extends TestCase
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

    private function singleOrder(array $overrides = []): LinkBuildingOrder
    {
        return LinkBuildingOrder::create(array_merge([
            'user_id'      => $this->client->id,
            'order_title'  => 'Test Order',
            'status'       => 'processing',
            'total_amount' => 150,
            'is_hidden'    => false,
        ], $overrides));
    }

    private function sessionOrder(string $session_id, array $overrides = []): LinkBuildingOrder
    {
        return $this->singleOrder(array_merge([
            'session_id'    => $session_id,
            'session_title' => 'Test Session',
        ], $overrides));
    }

    // ─── Client comment → admin notification ───────────────────────────────────

    public function test_client_comment_on_order_based_purchase_notifies_admin_with_comment_deep_link(): void
    {
        Bus::fake();
        $order = $this->singleOrder();

        $response = $this->actingAs($this->client, 'api')->postJson(
            "/api/orders/{$order->id}/comments",
            ['content' => 'The link on this order looks broken.']
        );

        $response->assertCreated();
        $comment_id = $response->json('data.id');

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'order_comment')
            ->firstOrFail();

        $expected_link = "/admin/orders/{$order->id}?comment_id={$comment_id}#comment-{$comment_id}";
        $this->assertSame($expected_link, $notification->link);
        $this->assertSame('order_comment', $notification->resource_type);
        $this->assertSame((string) $comment_id, $notification->resource_id);
        $this->assertSame($order->id, $notification->metadata['order_id']);
        $this->assertNull($notification->metadata['session_id']);
        $this->assertSame('single_order', $notification->metadata['purchase_type']);
        $this->assertSame($this->client->id, $notification->metadata['author_id']);

        Bus::assertDispatched(
            SendAdminCommentNotificationJob::class,
            fn ($job) => $job->view_comment_url === config('app.frontend_url') . $expected_link
        );
    }

    public function test_client_comment_on_session_based_purchase_notifies_admin_with_session_deep_link(): void
    {
        Bus::fake();
        $session_id = 'sess-' . uniqid();
        $order      = $this->sessionOrder($session_id);

        $response = $this->actingAs($this->client, 'api')->postJson(
            "/api/order-sessions/{$session_id}/comments",
            ['content' => 'Question about my multi-purchase order.']
        );

        $response->assertCreated();
        $comment_id = $response->json('data.id');

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'order_comment')
            ->firstOrFail();

        $expected_link = "/admin/orders/session/{$session_id}?comment_id={$comment_id}#comment-{$comment_id}";
        $this->assertSame($expected_link, $notification->link);
        $this->assertSame($session_id, $notification->metadata['session_id']);
        $this->assertSame('multi_purchase', $notification->metadata['purchase_type']);

        Bus::assertDispatched(
            SendAdminCommentNotificationJob::class,
            fn ($job) => $job->view_comment_url === config('app.frontend_url') . $expected_link
        );

        // Guard against the historical bug: the plural "/orders/sessions/" route never existed.
        $this->assertStringNotContainsString('/orders/sessions/', $notification->link);
    }

    // ─── Admin reply → client notification ─────────────────────────────────────

    public function test_admin_reply_on_order_based_purchase_notifies_client_with_comment_deep_link(): void
    {
        Bus::fake();
        $order = $this->singleOrder();

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/api/admin/orders/{$order->id}/comments",
            ['content' => 'We have fixed the link, thanks for flagging it.']
        );

        $response->assertCreated();
        $comment_id = $response->json('data.id');

        $notification = Notification::where('user_id', $this->client->id)
            ->where('type', 'order_comment')
            ->firstOrFail();

        $expected_link = "/orders/{$order->id}?comment_id={$comment_id}#comment-{$comment_id}";
        $this->assertSame($expected_link, $notification->link);
        $this->assertSame('order_comment', $notification->resource_type);
        $this->assertSame($this->admin->id, $notification->metadata['author_id']);

        Bus::assertDispatched(
            SendClientCommentReplyNotificationJob::class,
            fn ($job) => $job->view_reply_url === config('app.frontend_url') . $expected_link
        );
    }

    public function test_admin_reply_on_session_based_purchase_notifies_client_with_session_deep_link(): void
    {
        Bus::fake();
        $session_id = 'sess-' . uniqid();
        $order      = $this->sessionOrder($session_id);

        $response = $this->actingAs($this->admin, 'api')->postJson(
            "/api/admin/order-sessions/{$session_id}/comments",
            ['content' => 'Following up on your multi-purchase order.']
        );

        $response->assertCreated();
        $comment_id = $response->json('data.id');

        $notification = Notification::where('user_id', $this->client->id)
            ->where('type', 'order_comment')
            ->firstOrFail();

        $expected_link = "/orders/session/{$session_id}?comment_id={$comment_id}#comment-{$comment_id}";
        $this->assertSame($expected_link, $notification->link);

        Bus::assertDispatched(
            SendClientCommentReplyNotificationJob::class,
            fn ($job) => $job->view_reply_url === config('app.frontend_url') . $expected_link
        );
    }

    // ─── Recipient targeting sanity checks ──────────────────────────────────────

    public function test_admin_who_authored_the_reply_does_not_notify_themselves(): void
    {
        Bus::fake();
        $order = $this->singleOrder();

        $this->actingAs($this->admin, 'api')->postJson(
            "/api/admin/orders/{$order->id}/comments",
            ['content' => 'Internal note visible only to the client.']
        )->assertCreated();

        $this->assertSame(
            0,
            Notification::where('user_id', $this->admin->id)->where('type', 'order_comment')->count()
        );
    }

    public function test_client_comment_does_not_notify_other_clients(): void
    {
        Bus::fake();
        $order        = $this->singleOrder();
        $other_client = User::factory()->create(['is_active' => true]);
        $other_client->assignRole('client');

        $this->actingAs($this->client, 'api')->postJson(
            "/api/orders/{$order->id}/comments",
            ['content' => 'A private question about my own order.']
        )->assertCreated();

        $this->assertSame(
            0,
            Notification::where('user_id', $other_client->id)->count()
        );
    }
}
