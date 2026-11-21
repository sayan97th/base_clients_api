<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\LinkBuildingOrder;
use App\Models\LinkBuildingOrderCoupon;
use App\Models\NewContentOrder;
use App\Models\NewContentTier;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * GET /api/admin/coupons/{id}/usages
 *
 * Lists every order a coupon was applied to, with the client and order
 * details, so the admin team can track how each promo performs. The history
 * is a snapshot taken at checkout, so later price, tier, coupon or order
 * changes never alter what was charged.
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

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

    /**
     * Mirrors the checkout: the coupon pivot is created before the items,
     * inside one transaction, so the snapshot must wait for the commit.
     */
    private function checkoutLinkBuildingOrder(Coupon $coupon, ?string $session_id = 'session-1'): LinkBuildingOrder
    {
        return DB::transaction(function () use ($coupon, $session_id) {
            $order = LinkBuildingOrder::create([
                'user_id'                  => $this->client->id,
                'order_title'              => 'Spring links',
                'subtotal_before_discount' => 800.0,
                'total_amount'             => 600.0,
                'status'                   => 'pending',
                'session_id'               => $session_id,
            ]);
            $order->orderCoupons()->create(['coupon_id' => $coupon->id, 'discount_amount' => 200.0]);
            $order->items()->create([
                'dr_tier_id' => 'dr50',
                'quantity'   => 2,
                'unit_price' => 400.0,
                'subtotal'   => 800.0,
            ]);

            return $order;
        });
    }

    private function getUsage(Coupon $coupon)
    {
        return $this->actingAs($this->admin, 'api')->getJson("/api/admin/coupons/{$coupon->id}/usages");
    }

    // ─── Listing ──────────────────────────────────────────────────────────────

    public function test_it_lists_every_order_the_coupon_was_applied_to(): void
    {
        $coupon              = $this->makeCoupon();
        $link_building_order = $this->checkoutLinkBuildingOrder($coupon);

        DB::transaction(function () use ($coupon) {
            $order = NewContentOrder::create([
                'user_id'                  => $this->client->id,
                'subtotal_before_discount' => 100.0,
                'total_amount'             => 75.0,
                'status'                   => 'processing',
                'session_id'               => 'session-1',
            ]);
            $order->orderCoupons()->create(['coupon_id' => $coupon->id, 'discount_amount' => 25.0]);
            $order->items()->create(['tier_id' => 'nc', 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);
        });

        $response = $this->getUsage($coupon)
            ->assertOk()
            ->assertJsonPath('coupon.code', 'CONF25')
            ->assertJsonPath('summary.order_count', 2)
            ->assertJsonPath('summary.checkout_count', 1)
            ->assertJsonPath('summary.client_count', 1)
            ->assertJsonCount(2, 'data');

        $this->assertEqualsWithDelta(225.0, $response->json('summary.total_discount'), 0.001);
        $this->assertEqualsWithDelta(675.0, $response->json('summary.net_revenue'), 0.001);

        $usage = collect($response->json('data'))->firstWhere('product_type', 'link_building');

        $this->assertSame($link_building_order->id, $usage['order_id']);
        $this->assertSame('Kennedy Client', $usage['client']['name']);
        $this->assertSame('DR 50+', $usage['items'][0]['label']);
        $this->assertSame(2, $usage['items_quantity']);
        $this->assertSame('/admin/orders/' . $link_building_order->id, $usage['order_url']);
        $this->assertFalse($usage['is_order_deleted']);
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

        $response = $this->getUsage($coupon)
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.gift_card_claim.status', GiftCardRewardClaim::STATUS_PENDING_DETAILS);

        $this->assertEqualsWithDelta(40.0, $response->json('summary.total_reward'), 0.001);
        $this->assertEqualsWithDelta(0.0, $response->json('summary.total_discount'), 0.001);
    }

    public function test_it_returns_an_empty_history_for_an_unused_coupon(): void
    {
        $this->getUsage($this->makeCoupon())
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

    // ─── Price snapshot ───────────────────────────────────────────────────────

    public function test_the_history_keeps_the_price_charged_after_product_prices_change(): void
    {
        $coupon = $this->makeCoupon();
        $this->checkoutLinkBuildingOrder($coupon);

        DrTier::where('id', 'dr50')->update(['price_per_link' => 950.0, 'label' => 'DR 50 Premium']);
        $coupon->update(['discount_value' => 50.0, 'name' => 'Renamed promo']);

        $response = $this->getUsage($coupon)->assertOk();
        $usage    = $response->json('data.0');

        $this->assertSame('DR 50+', $usage['items'][0]['label']);
        $this->assertEqualsWithDelta(400.0, $usage['items'][0]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(800.0, $usage['subtotal_before_discount'], 0.001);
        $this->assertEqualsWithDelta(200.0, $usage['discount_amount'], 0.001);
        $this->assertEqualsWithDelta(600.0, $usage['total_amount'], 0.001);
        $this->assertEqualsWithDelta(25.0, $usage['coupon_terms']['discount_value'], 0.001);
        $this->assertSame('Conference 25', $usage['coupon_terms']['name']);
    }

    public function test_the_history_keeps_the_purchase_after_the_order_is_edited_or_deleted(): void
    {
        $coupon = $this->makeCoupon();
        $order  = $this->checkoutLinkBuildingOrder($coupon);

        $order->update(['total_amount' => 1.0, 'status' => 'cancelled']);
        $order->delete();

        $this->assertDatabaseMissing('link_building_order_coupons', ['order_id' => $order->id]);

        $usage = $this->getUsage($coupon)
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->json('data.0');

        $this->assertTrue($usage['is_order_deleted']);
        $this->assertNull($usage['order_url']);
        $this->assertEqualsWithDelta(600.0, $usage['total_amount'], 0.001);
        $this->assertSame('Kennedy Client', $usage['client']['name']);
    }

    public function test_the_order_status_stays_live_while_the_order_exists(): void
    {
        $coupon = $this->makeCoupon();
        $order  = $this->checkoutLinkBuildingOrder($coupon);

        $order->update(['status' => 'completed']);

        $this->getUsage($coupon)->assertOk()->assertJsonPath('data.0.status', 'completed');
    }

    public function test_snapshots_cannot_be_updated(): void
    {
        $this->checkoutLinkBuildingOrder($this->makeCoupon());

        $this->expectException(LogicException::class);

        CouponRedemption::firstOrFail()->update(['total_amount' => 1.0]);
    }

    public function test_backfill_command_records_coupon_uses_missing_from_the_history(): void
    {
        $coupon = $this->makeCoupon();

        // A coupon use recorded before the history existed.
        $order = LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 400.0,
            'total_amount'             => 300.0,
            'status'                   => 'pending',
        ]);
        $order->items()->create(['dr_tier_id' => 'dr50', 'quantity' => 1, 'unit_price' => 400.0, 'subtotal' => 400.0]);
        LinkBuildingOrderCoupon::withoutEvents(fn () => LinkBuildingOrderCoupon::create([
            'order_id'        => $order->id,
            'coupon_id'       => $coupon->id,
            'discount_amount' => 100.0,
        ]));

        $this->assertSame(0, CouponRedemption::count());

        $this->artisan('coupons:backfill-redemptions')->assertSuccessful();
        $this->artisan('coupons:backfill-redemptions')->assertSuccessful();

        $this->assertSame(1, CouponRedemption::count());
        $this->assertEqualsWithDelta(100.0, CouponRedemption::first()->discount_amount, 0.001);
    }

    public function test_backfill_adds_the_other_orders_of_a_past_checkout(): void
    {
        $coupon = $this->makeCoupon();

        // A past checkout: the coupon only discounted the link building order.
        $order = LinkBuildingOrder::create([
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 400.0,
            'total_amount'             => 300.0,
            'status'                   => 'pending',
            'session_id'               => 'legacy-session',
        ]);
        $order->items()->create(['dr_tier_id' => 'dr50', 'quantity' => 1, 'unit_price' => 400.0, 'subtotal' => 400.0]);
        LinkBuildingOrderCoupon::withoutEvents(fn () => LinkBuildingOrderCoupon::create([
            'order_id'        => $order->id,
            'coupon_id'       => $coupon->id,
            'discount_amount' => 100.0,
        ]));

        $companion = NewContentOrder::create([
            'user_id'                  => $this->client->id,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 100.0,
            'status'                   => 'processing',
            'session_id'               => 'legacy-session',
        ]);
        $companion->items()->create(['tier_id' => 'nc', 'quantity' => 1, 'unit_price' => 100.0, 'subtotal' => 100.0]);

        $this->artisan('coupons:backfill-redemptions')->assertSuccessful();
        $this->artisan('coupons:backfill-redemptions')->assertSuccessful();

        $this->assertSame(2, CouponRedemption::count());
        $this->assertSame(1, CouponRedemption::applied()->count());

        $this->getUsage($coupon)
            ->assertOk()
            ->assertJsonPath('summary.order_count', 1)
            ->assertJsonPath('checkouts.0.order_count', 2)
            ->assertJsonPath('checkouts.0.orders.1.product_type', 'new_content')
            ->assertJsonPath('checkouts.0.orders.1.is_coupon_applied', false);
    }
}
