<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use App\Services\GiftCardRewardService;
use Database\Seeders\HolidayPromoCouponSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Coupon ceiling (max_discount_amount). The client asked for HAPPYHOLIDAYS to
 * keep giving 10% back, but never more than $1,000 per order: a client may
 * order $50,000 and still receive a $1,000 gift card at most.
 *
 * The ceiling is optional, works for gift card and percentage coupons and is
 * ignored for fixed amount coupons.
 */
class CouponMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $admin;

    private User $client;

    private CouponService $coupon_service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->admin          = $this->makeUserWithRole('admin');
        $this->client         = $this->makeUserWithRole('client');
        $this->coupon_service = new CouponService();
    }

    private function validateCode(string $code, float $order_amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'                 => $code,
            'order_amount'         => $order_amount,
            'cart_product_types'   => ['link_building'],
            'product_type_amounts' => ['link_building' => $order_amount],
        ])->assertOk();
    }

    private function orderEntry(string $product_type, float $total_amount): array
    {
        return [
            'product_type' => $product_type,
            'order_id'     => (string) Str::uuid(),
            'total_amount' => $total_amount,
        ];
    }

    // ── Calculation ──────────────────────────────────────────────────────────

    public function test_gift_card_reward_is_capped_at_the_maximum_amount(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame(1000.0, $this->coupon_service->calculateRewardAmount($coupon, 50000.0));
        $this->assertSame(1000.0, $this->coupon_service->calculateRewardAmount($coupon, 10000.0));
        $this->assertSame(5000.0, $this->coupon_service->calculateUncappedRewardAmount($coupon, 50000.0));
    }

    public function test_gift_card_reward_below_the_maximum_is_not_changed(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->assertSame(500.0, $this->coupon_service->calculateRewardAmount($coupon, 5000.0));
        $this->assertSame(999.99, $this->coupon_service->calculateRewardAmount($coupon, 9999.9));
    }

    public function test_gift_card_reward_without_a_maximum_is_unlimited(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => null]);

        $this->assertSame(5000.0, $this->coupon_service->calculateRewardAmount($coupon, 50000.0));
    }

    public function test_percentage_discount_is_capped_at_the_maximum_amount(): void
    {
        $coupon = $this->makeRegularCoupon(['discount_value' => 20.0, 'max_discount_amount' => 300.0]);

        $result = $this->coupon_service->validateAndCalculate($coupon, 5000.0, $this->client->id);

        $this->assertTrue($result['valid']);
        $this->assertEquals(300.0, $result['discount_amount']);
    }

    public function test_fixed_amount_coupon_ignores_a_stored_maximum(): void
    {
        $coupon = $this->makeRegularCoupon([
            'code'                => 'FLAT500',
            'discount_type'       => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT,
            'discount_value'      => 500.0,
            'max_discount_amount' => 100.0,
        ]);

        $result = $this->coupon_service->validateAndCalculate($coupon, 2000.0, $this->client->id);

        $this->assertEquals(500.0, $result['discount_amount']);
        $this->assertNull($coupon->maxDiscountAmount());
    }

    // ── Promo code validation at checkout ────────────────────────────────────

    public function test_validation_reports_the_capped_gift_card_for_a_large_order(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('happyholidays', 50000.0)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('max_discount_amount', 1000)
            ->assertJsonPath('reward_amount', 1000)
            ->assertJsonPath('is_reward_capped', true)
            ->assertJsonPath('message', 'Promo code applied. You will receive one gift card for this whole order, worth 10% of your order total, up to $1,000.');
    }

    public function test_validation_reports_the_full_gift_card_below_the_cap(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->validateCode('HAPPYHOLIDAYS', 4000.0)
            ->assertJsonPath('reward_amount', 400)
            ->assertJsonPath('is_reward_capped', false);
    }

    public function test_validation_message_has_no_cap_when_the_coupon_is_unlimited(): void
    {
        $this->makeRewardCoupon();

        $this->validateCode('HAPPYHOLIDAYS', 50000.0)
            ->assertJsonPath('max_discount_amount', null)
            ->assertJsonPath('reward_amount', 5000)
            ->assertJsonPath('message', 'Promo code applied. You will receive one gift card for this whole order, worth 10% of your order total.');
    }

    // ── Gift card claim created at checkout ──────────────────────────────────

    public function test_checkout_claim_is_capped_and_keeps_a_snapshot_of_the_cap(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);
        $service = app(GiftCardRewardService::class);

        $claim = $service->createClaimForCheckout(
            $this->client,
            $coupon,
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 30000.0), $this->orderEntry('new_content', 20000.0)],
        );

        $this->assertSame(50000.0, $claim->order_total);
        $this->assertSame(1000.0, $claim->reward_amount);
        $this->assertSame(1000.0, $claim->max_reward_amount);
        $this->assertTrue($claim->isRewardCapped());

        // Raising the cap later never changes a gift card already earned.
        $coupon->update(['max_discount_amount' => 2000.0]);

        $formatted = $service->formatForClient($claim->fresh());
        $this->assertEquals(1000.0, $formatted['reward_amount']);
        $this->assertEquals(1000.0, $formatted['max_reward_amount']);
        $this->assertTrue($formatted['is_reward_capped']);
    }

    public function test_checkout_claim_below_the_cap_is_not_flagged_as_capped(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $claim = app(GiftCardRewardService::class)->createClaimForCheckout(
            $this->client,
            $coupon,
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 2500.0)],
        );

        $this->assertSame(250.0, $claim->reward_amount);
        $this->assertFalse($claim->isRewardCapped());
    }

    // ── Admin create and edit ────────────────────────────────────────────────

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'code'           => 'BIGBACK',
            'name'           => 'Big Back',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10,
            'applies_to'     => Coupon::APPLIES_TO_ALL,
            'product_types'  => [],
            'dr_tier_ids'    => [],
            'expires_at'     => null,
            'is_active'      => true,
        ], $overrides);
    }

    public function test_admin_can_create_a_gift_card_coupon_with_a_maximum(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->adminPayload(['max_discount_amount' => 1000]))
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', 1000);

        $this->assertEquals(1000.0, Coupon::where('code', 'BIGBACK')->value('max_discount_amount'));
    }

    public function test_admin_maximum_must_be_greater_than_zero(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->adminPayload(['max_discount_amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_discount_amount' => 'The maximum amount must be greater than 0.']);
    }

    public function test_fixed_amount_coupon_never_stores_a_maximum(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->adminPayload([
                'discount_type'       => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT,
                'discount_value'      => 100,
                'max_discount_amount' => 50,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_admin_can_change_and_remove_the_maximum(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}", ['max_discount_amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1500);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}", ['max_discount_amount' => null])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_switching_to_fixed_amount_clears_the_maximum(): void
    {
        $coupon = $this->makeRegularCoupon(['max_discount_amount' => 300.0]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}", ['discount_type' => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_editing_other_fields_keeps_the_maximum(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}", ['name' => 'Renamed promo'])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1000);
    }

    // ── HAPPYHOLIDAYS ────────────────────────────────────────────────────────

    public function test_holiday_promo_seeder_caps_the_gift_card_at_one_thousand_dollars(): void
    {
        $this->seed(HolidayPromoCouponSeeder::class);

        $coupon = Coupon::where('code', HolidayPromoCouponSeeder::CODE)->firstOrFail();

        $this->assertEquals(1000.0, $coupon->max_discount_amount);
        $this->assertSame(1000.0, $this->coupon_service->calculateRewardAmount($coupon, 20000.0));
        $this->assertSame(1000.0, $this->coupon_service->calculateRewardAmount($coupon, 50000.0));
        $this->assertSame(800.0, $this->coupon_service->calculateRewardAmount($coupon, 8000.0));
    }
}
