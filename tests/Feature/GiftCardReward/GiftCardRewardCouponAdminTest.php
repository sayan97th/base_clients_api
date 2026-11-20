<?php

namespace Tests\Feature\GiftCardReward;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Admins set up the money back promo from the coupons screen by choosing the
 * "gift_card_reward" discount type. POST /api/admin/coupons
 */
class GiftCardRewardCouponAdminTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->admin = $this->makeUserWithRole('admin');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code'           => 'HAPPYHOLIDAYS',
            'name'           => 'Happy Holidays 10% Back',
            'description'    => 'Get 10% of your order back as a gift card.',
            'discount_type'  => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value' => 10,
            'applies_to'     => 'all',
            'expires_at'     => now()->addMonth()->format('Y-m-d'),
            'is_active'      => true,
        ], $overrides);
    }

    public function test_admin_can_create_the_holiday_money_back_promo(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->payload())
            ->assertSuccessful();

        $coupon = Coupon::where('code', 'HAPPYHOLIDAYS')->firstOrFail();
        $this->assertTrue($coupon->isGiftCardReward());
        $this->assertEquals(10.0, $coupon->discount_value);
        $this->assertTrue($coupon->isRunning());
    }

    public function test_reward_percentage_cannot_exceed_100(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->payload(['discount_value' => 150]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value' => 'The reward percentage must not be greater than 100.']);
    }

    public function test_reward_percentage_must_be_positive(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $this->payload(['discount_value' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    public function test_clients_cannot_create_promo_codes(): void
    {
        $this->actingAs($this->makeUserWithRole('client'), 'api')
            ->postJson('/api/admin/coupons', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Coupon::count());
    }

    public function test_validate_endpoint_returns_reward_details_for_the_client(): void
    {
        $this->makeRewardCoupon();
        $client = $this->makeUserWithRole('client');

        $this->actingAs($client, 'api')
            ->postJson('/api/coupons/validate', [
                'code'               => 'happyholidays',
                'order_amount'       => 1000,
                'cart_product_types' => ['link_building'],
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('is_gift_card_reward', true)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('reward_percentage', 10)
            ->assertJsonPath('reward_amount', 100)
            ->assertJsonCount(3, 'gift_card_brands');
    }

    public function test_validate_endpoint_rejects_the_promo_once_it_expired(): void
    {
        $this->makeRewardCoupon(['expires_at' => now()->subDay()]);

        $this->actingAs($this->makeUserWithRole('client'), 'api')
            ->postJson('/api/coupons/validate', ['code' => 'HAPPYHOLIDAYS', 'order_amount' => 1000])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('is_gift_card_reward', false);
    }
}
