<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderStatusChangeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStatusChangeMailTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    public function test_completed_order_links_to_the_live_links_section_of_the_order_page(): void
    {
        $mail = new OrderStatusChangeMail(
            user: $this->client,
            status: 'completed',
            order_id: 'order-uuid-1',
        );

        $order_url = $mail->content()->with['order_url'];

        $this->assertSame(
            config('app.frontend_url') . '/orders/order-uuid-1#live-links',
            $order_url
        );
    }

    public function test_processing_order_links_to_the_order_page_without_an_anchor(): void
    {
        $mail = new OrderStatusChangeMail(
            user: $this->client,
            status: 'processing',
            order_id: 'order-uuid-1',
        );

        $order_url = $mail->content()->with['order_url'];

        $this->assertSame(config('app.frontend_url') . '/orders/order-uuid-1', $order_url);
        $this->assertStringNotContainsString('#live-links', $order_url);
    }

    public function test_standalone_placement_links_to_its_own_detail_page_instead_of_a_nonexistent_order(): void
    {
        $mail = new OrderStatusChangeMail(
            user: $this->client,
            status: 'completed',
            order_id: 'BL-1234',
            placement_id: 'placement-uuid-1',
        );

        $order_url = $mail->content()->with['order_url'];

        $this->assertSame(
            config('app.frontend_url') . '/link-building/placements/placement-uuid-1',
            $order_url
        );
        $this->assertStringNotContainsString('/orders/', $order_url);
        $this->assertStringNotContainsString('#live-links', $order_url);
    }

    public function test_subject_line_does_not_contain_an_em_dash(): void
    {
        $mail = new OrderStatusChangeMail(
            user: $this->client,
            status: 'completed',
            order_id: 'order-uuid-1',
        );

        $this->assertStringNotContainsString('—', $mail->envelope()->subject);
    }
}
