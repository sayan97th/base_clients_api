<?php

namespace Tests\Unit\Models;

use App\Models\GiftCardRewardClaim;
use Tests\TestCase;

/**
 * GiftCardRewardClaim::isRewardCapped tells whether the promo limit lowered
 * the gift card. It drives the "capped at $1,000" note in the admin email and
 * the client copy, so it must only be true when the limit really applied.
 */
class GiftCardRewardClaimRewardCapTest extends TestCase
{
    private function claim(float $order_total, float $reward_amount, ?float $max_reward_amount): GiftCardRewardClaim
    {
        return new GiftCardRewardClaim([
            'order_total'       => $order_total,
            'reward_percentage' => 10.0,
            'reward_amount'     => $reward_amount,
            'max_reward_amount' => $max_reward_amount,
        ]);
    }

    public function test_a_large_order_limited_to_one_thousand_dollars_is_capped(): void
    {
        $this->assertTrue($this->claim(50000.0, 1000.0, 1000.0)->isRewardCapped());
        $this->assertTrue($this->claim(10000.10, 1000.0, 1000.0)->isRewardCapped());
    }

    public function test_an_order_that_lands_exactly_on_the_limit_is_not_capped(): void
    {
        // $10,000 at 10% is exactly $1,000, nothing was taken away.
        $this->assertFalse($this->claim(10000.0, 1000.0, 1000.0)->isRewardCapped());
    }

    public function test_an_order_below_the_limit_is_not_capped(): void
    {
        $this->assertFalse($this->claim(8000.0, 800.0, 1000.0)->isRewardCapped());
    }

    public function test_a_claim_without_a_limit_is_never_capped(): void
    {
        // Claims created before the limit existed have no snapshot.
        $this->assertFalse($this->claim(50000.0, 5000.0, null)->isRewardCapped());
    }

    public function test_the_limit_snapshot_is_cast_to_a_float(): void
    {
        $this->assertIsFloat($this->claim(50000.0, 1000.0, 1000.0)->max_reward_amount);
    }
}
