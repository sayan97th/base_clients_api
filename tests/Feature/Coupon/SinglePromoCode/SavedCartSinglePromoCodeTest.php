<?php

namespace Tests\Feature\Coupon\SinglePromoCode;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Coupon\InteractsWithSinglePromoCode;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * One promo code per order in the saved cart (cross device sync).
 * PUT /api/cart, GET /api/cart
 */
class SavedCartSinglePromoCodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;
    use InteractsWithSinglePromoCode;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    public function test_a_cart_with_one_promo_code_is_saved(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$this->savedCartCoupon('SAVE10')]])
            ->assertOk();

        $this->assertCount(1, Cart::where('user_id', $this->client->id)->firstOrFail()->payload['applied_coupons']);
    }

    public function test_a_cart_with_two_promo_codes_is_rejected(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', [
                'items'           => [],
                'applied_coupons' => [$this->savedCartCoupon('SAVE10'), $this->savedCartCoupon('SAVE20')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applied_coupons']);

        $this->assertSame(0, Cart::where('user_id', $this->client->id)->count());
    }

    /**
     * Regression: the saved cart used to accept only percentage and fixed
     * amount coupons, so a cart holding HAPPYHOLIDAYS failed to sync and the
     * promo was lost on other devices.
     */
    public function test_a_cart_holding_the_money_back_promo_is_saved_and_restored(): void
    {
        $holiday = $this->savedCartCoupon('HAPPYHOLIDAYS', Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD);

        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$holiday]])
            ->assertOk();

        $this->actingAs($this->client, 'api')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.applied_coupons.0.code', 'HAPPYHOLIDAYS')
            ->assertJsonPath('data.applied_coupons.0.discount_type', Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD);
    }

    public function test_an_unknown_discount_type_is_still_rejected(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$this->savedCartCoupon('FREE', 'free_everything')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['applied_coupons.0.discount_type']);
    }

    public function test_a_cart_without_promo_codes_is_not_affected(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => []])
            ->assertOk();
    }
}
