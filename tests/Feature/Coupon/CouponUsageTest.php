<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\NewContentOrder;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/admin/coupons/{id}/usages
 *
 * Lists every order a coupon was applied to, with the client and order
 * details, so the admin team can track how each promo performs.
 */
class CouponUsageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['client' => 'Client', 'admin' => 'Admin'] as $name => $display_name) {
            Role::firstOrCreate(['name' => $name], ['display_name' => $display_name, 'description' => $display_name]);
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->client = User::factory()->create([
            'is_active'  => true,
            'first_name' => 'Kennedy',
            'last_name'  => 'Client',
        ]);
        $this->client->assignRole('client');

        DrTier::create([
            'id'             => 'dr50',
            'label'          => 'DR 50+',
            'min_dr'         => 50,
            'max_dr'         => 59,
            'traffic_range'  => '10k-20k',
            'word_count'     => 500,
            'price_per_link' => 400.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);

        NewContentTier::create([
            'id'              => 'nc',
            'label'           => 'Article',
            'turnaround_time' => '6 Days',
            'price'           => 100.0,
            'is_active'       => true,
        ]);
    }

    private function makeCoupon(): Coupon
    {
        return Coupon::create([
            'code'           => 'CONF25',
            'name'           => 'Conference 25',
            'discount_type'  => 'percentage',
            'discount_value' => 25.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 1,
        ]);
    }

    public function test_it_lists_every_order_the_coupon_was_applied_to(): void
    {
        $coupon = $this->makeCoupon();

        $link_building_order = LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'order_title'              => 'Spring links',
            'subtotal_before_discount' => 800.0,
            'total_amount'             => 600.0,
            'status'                   => 'pending',
            'session_id'               => 'session-1',
        ]);
        $link_building_order->items()->create([
            'dr_tier_id' => 'dr50',
            'quantity'   => 2,
            'unit_price' => 400.0,
            'subtotal'   => 800.0,
        ]);
        $link_building_order->orderCoupons()->create(['coupon_id' => $coupon->id, 'discount_amount' => 200.0]);

        $new_content_order = NewContentOrder::create([
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 75.0,
            'status'                   => 'processing',
            'session_id'               => 'session-1',
        ]);
        $new_content_order->items()->create([
            'tier_id'    => 'nc',
            'quantity'   => 1,
            'unit_price' => 100.0,
            'subtotal'   => 100.0,
        ]);
        $new_content_order->orderCoupons()->create(['coupon_id' => $coupon->id, 'discount_amount' => 25.0]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}/usages")
            ->assertOk()
            ->assertJsonPath('coupon.code', 'CONF25')
            ->assertJsonPath('summary.order_count', 2)
            ->assertJsonPath('summary.checkout_count', 1)
            ->assertJsonPath('summary.client_count', 1)
            ->assertJsonCount(2, 'data');

        $this->assertEqualsWithDelta(225.0, $response->json('summary.total_discount'), 0.001);
        $this->assertEqualsWithDelta(675.0, $response->json('summary.net_revenue'), 0.001);

        $link_building_usage = collect($response->json('data'))->firstWhere('product_type', 'link_building');

        $this->assertSame($link_building_order->id, $link_building_usage['order_id']);
        $this->assertSame('Kennedy Client', $link_building_usage['client']['name']);
        $this->assertSame('DR 50+', $link_building_usage['items'][0]['label']);
        $this->assertSame(2, $link_building_usage['items_quantity']);
        $this->assertSame('/admin/orders/' . $link_building_order->id, $link_building_usage['order_url']);
    }

    public function test_gift_card_reward_coupons_list_the_orders_from_their_claims(): void
    {
        $coupon = Coupon::create([
            'code'           => 'HAPPYHOLIDAYS',
            'name'           => 'Happy Holidays',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 1,
        ]);

        $order = LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 400.0,
            'total_amount'             => 400.0,
            'status'                   => 'pending',
            'session_id'               => 'session-2',
        ]);

        GiftCardRewardClaim::create([
            'coupon_id'         => $coupon->id,
            'user_id'           => $this->client->id,
            'session_id'        => 'session-2',
            'order_ids'         => [$order->id],
            'product_types'     => ['link_building'],
            'coupon_code'       => 'HAPPYHOLIDAYS',
            'order_total'       => 400.0,
            'reward_percentage' => 10.0,
            'reward_amount'     => 40.0,
            'status'            => GiftCardRewardClaim::STATUS_PENDING_DETAILS,
        ]);

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}/usages")
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.gift_card_claim.status', GiftCardRewardClaim::STATUS_PENDING_DETAILS);

        $this->assertEqualsWithDelta(40.0, $response->json('summary.total_reward'), 0.001);
        $this->assertEqualsWithDelta(0.0, $response->json('summary.total_discount'), 0.001);
    }

    public function test_it_returns_an_empty_history_for_an_unused_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}/usages")
            ->assertOk()
            ->assertJsonPath('summary.order_count', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_it_returns_not_found_for_an_unknown_coupon(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/coupons/00000000-0000-0000-0000-000000000000/usages')
            ->assertNotFound();
    }

    public function test_clients_cannot_see_coupon_usage(): void
    {
        $coupon = $this->makeCoupon();

        $this->actingAs($this->client, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}/usages")
            ->assertForbidden();
    }
}
