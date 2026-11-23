<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * The saved cart (PUT and GET /api/cart) keeps the applied promo code between
 * devices and page reloads. It must keep the limit too, otherwise the
 * checkout estimate would show 10% of a $50,000 order after a reload instead
 * of the $1,000 gift card the client will really get.
 */
class SavedCartMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    private function savedCoupon(array $overrides = []): array
    {
        return array_merge([
            'coupon_id'           => (string) Str::uuid(),
            'code'                => 'HAPPYHOLIDAYS',
            'coupon_name'         => 'Happy Holidays 10% Back',
            'discount_amount'     => 0,
            'discount_type'       => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value'      => 10,
            'max_discount_amount' => 1000,
        ], $overrides);
    }

    public function test_the_limit_survives_saving_and_reloading_the_cart(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$this->savedCoupon()]])
            ->assertOk();

        $this->actingAs($this->client, 'api')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.applied_coupons.0.code', 'HAPPYHOLIDAYS')
            ->assertJsonPath('data.applied_coupons.0.max_discount_amount', 1000);
    }

    public function test_a_coupon_without_a_limit_is_saved_as_before(): void
    {
        $coupon = $this->savedCoupon();
        unset($coupon['max_discount_amount']);

        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$coupon]])
            ->assertOk();

        $this->actingAs($this->client, 'api')
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonMissingPath('data.applied_coupons.0.max_discount_amount');
    }

    public function test_a_null_limit_is_accepted(): void
    {
        $this->actingAs($this->client, 'api')
            ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$this->savedCoupon(['max_discount_amount' => null])]])
            ->assertOk();
    }

    public function test_an_invalid_limit_is_rejected(): void
    {
        foreach ([0, -1, 'abc'] as $value) {
            $this->actingAs($this->client, 'api')
                ->putJson('/api/cart', ['items' => [], 'applied_coupons' => [$this->savedCoupon(['max_discount_amount' => $value])]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['applied_coupons.0.max_discount_amount']);
        }
    }
}
