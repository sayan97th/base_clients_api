<?php

namespace Tests\Unit\Services;

use App\Services\OrderSessionCommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSessionCommentServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderSessionCommentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrderSessionCommentService();
    }

    // ─── buildCommentPath ───────────────────────────────────────────────────────

    public function test_build_comment_path_for_client_order_based(): void
    {
        $path = $this->service->buildCommentPath(false, 'order-uuid-1', null, 42);

        $this->assertSame('/orders/order-uuid-1?comment_id=42#comment-42', $path);
    }

    public function test_build_comment_path_for_client_session_based(): void
    {
        $path = $this->service->buildCommentPath(false, null, 'session-abc', 7);

        $this->assertSame('/orders/session/session-abc?comment_id=7#comment-7', $path);
    }

    public function test_build_comment_path_for_admin_order_based(): void
    {
        $path = $this->service->buildCommentPath(true, 'order-uuid-1', null, 42);

        $this->assertSame('/admin/orders/order-uuid-1?comment_id=42#comment-42', $path);
    }

    public function test_build_comment_path_for_admin_session_based(): void
    {
        $path = $this->service->buildCommentPath(true, null, 'session-abc', 7);

        $this->assertSame('/admin/orders/session/session-abc?comment_id=7#comment-7', $path);
    }

    public function test_build_comment_path_uses_singular_session_segment_not_the_legacy_plural(): void
    {
        $path = $this->service->buildCommentPath(false, null, 'session-abc', 1);

        $this->assertStringNotContainsString('/orders/sessions/', $path);
        $this->assertStringContainsString('/orders/session/', $path);
    }

    // ─── buildCommentUrl ────────────────────────────────────────────────────────

    public function test_build_comment_url_prefixes_the_base_url(): void
    {
        $url = $this->service->buildCommentUrl(
            'https://admin.example.com',
            true,
            'order-uuid-1',
            null,
            42
        );

        $this->assertSame(
            'https://admin.example.com/admin/orders/order-uuid-1?comment_id=42#comment-42',
            $url
        );
    }

    public function test_build_comment_url_trims_a_trailing_slash_from_the_base_url(): void
    {
        $url = $this->service->buildCommentUrl(
            'https://client.example.com/',
            false,
            null,
            'session-xyz',
            9
        );

        $this->assertSame(
            'https://client.example.com/orders/session/session-xyz?comment_id=9#comment-9',
            $url
        );
    }
}
