<?php

namespace Tests\Feature\Coupon\SinglePromoCode;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\Invoice;
use App\Models\LinkBuildingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Coupon\InteractsWithSinglePromoCode;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * One promo code per order, "Request Invoice" (pay later) checkout.
 * POST /api/cart/checkout/deferred
 *
 * Any attempt to stack promo codes (regular or money back) is rejected with
 * "This promo cannot be combined with other offers." and nothing is written.
 */
class DeferredCheckoutSinglePromoCodeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;
    use InteractsWithSinglePromoCode;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client  = $this->makeUserWithRole('client');
        $this->dr_tier = $this->makeDrTier();
    }

    private function payLater(array $coupon_ids): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', [
            'deferred_payment'    => true,
            'total_amount'        => 800.0,
            'session_id'          => (string) Str::uuid(),
            'order_title'         => 'Single Promo Order',
            'coupon_ids'          => $coupon_ids,
            'link_building_items' => $this->linkBuildingItems($this->dr_tier->id),
        ]);
    }

    private function assertNothingWasWritten(Coupon ...$coupons): void
    {
        $this->assertSame(0, LinkBuildingOrder::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, GiftCardRewardClaim::count());

        foreach ($coupons as $coupon) {
            $this->assertSame(0, $coupon->fresh()->times_used, "{$coupon->code} must not consume a use.");
        }
    }

    public function test_two_regular_promo_codes_cannot_be_combined(): void
    {
        $first  = $this->makePromoCode('SAVE10');
        $second = $this->makePromoCode('SAVE20', 20.0);

        $this->payLater([$first->id, $second->id])
            ->assertStatus(422)
            ->assertJsonPath('message', Coupon::COMBINATION_ERROR_MESSAGE)
            ->assertJsonValidationErrors(['coupon_ids' => Coupon::COMBINATION_ERROR_MESSAGE]);

        $this->assertNothingWasWritten($first, $second);
    }

    public function test_the_money_back_promo_cannot_be_combined_with_a_regular_promo_code(): void
    {
        $holiday = $this->makeRewardCoupon();
        $regular = $this->makePromoCode('SAVE10');

        $this->payLater([$holiday->id, $regular->id])
            ->assertStatus(422)
            ->assertJsonPath('message', Coupon::COMBINATION_ERROR_MESSAGE);

        $this->assertNothingWasWritten($holiday, $regular);
    }

    public function test_three_promo_codes_are_rejected_too(): void
    {
        $coupons = [
            $this->makePromoCode('SAVE10'),
            $this->makePromoCode('SAVE20', 20.0),
            $this->makePromoCode('SAVE30', 30.0),
        ];

        $this->payLater(array_map(fn (Coupon $coupon) => $coupon->id, $coupons))
            ->assertStatus(422)
            ->assertJsonPath('message', Coupon::COMBINATION_ERROR_MESSAGE);

        $this->assertNothingWasWritten(...$coupons);
    }

    public function test_a_single_regular_promo_code_is_still_accepted(): void
    {
        $coupon = $this->makePromoCode('SAVE10');

        $this->payLater([$coupon->id])->assertOk();

        $this->assertSame(1, LinkBuildingOrder::count());
        $this->assertSame(1, $coupon->fresh()->times_used);
    }

    public function test_the_money_back_promo_alone_is_still_accepted(): void
    {
        $holiday = $this->makeRewardCoupon();

        $this->payLater([$holiday->id])->assertOk();

        $this->assertSame(1, $holiday->fresh()->times_used);
        $this->assertSame(1, GiftCardRewardClaim::count());
    }

    public function test_the_same_promo_code_sent_twice_counts_as_one(): void
    {
        $coupon = $this->makePromoCode('SAVE10');

        $this->payLater([$coupon->id, $coupon->id])->assertOk();

        $this->assertSame(1, $coupon->fresh()->times_used);
    }

    public function test_a_checkout_without_promo_codes_is_not_affected(): void
    {
        $this->payLater([])->assertOk();

        $this->assertSame(1, LinkBuildingOrder::count());
    }
}
