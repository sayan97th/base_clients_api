<?php

namespace Tests\Feature\GiftCardReward;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Models\DrTier;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * "Request Invoice" (pay later) checkouts earn the HAPPYHOLIDAYS gift card
 * too. POST /api/cart/checkout/deferred
 */
class GiftCardRewardDeferredCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;
    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');

        $this->dr_tier = DrTier::create([
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
    }

    private function payLater(array $coupon_ids, int $quantity = 2, ?array $gift_card_reward = null): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/cart/checkout/deferred', array_filter([
            'deferred_payment'    => true,
            'total_amount'        => 400.0 * $quantity,
            'session_id'          => (string) Str::uuid(),
            'order_title'         => 'Holiday Invoice Order',
            'coupon_ids'          => $coupon_ids,
            'gift_card_reward'    => $gift_card_reward,
            'link_building_items' => [[
                'dr_tier_id' => $this->dr_tier->id,
                'quantity'   => $quantity,
                'unit_price' => 400.0,
                'placements' => [[
                    'row_index'    => 0,
                    'keyword'      => 'holiday keyword',
                    'landing_page' => 'https://example.com/page',
                    'exact_match'  => false,
                ]],
            ]],
        ], fn ($value) => $value !== null));
    }

    public function test_pay_later_with_details_records_a_submitted_claim_and_notifies(): void
    {
        $coupon = $this->makeRewardCoupon();

        $response = $this->payLater([$coupon->id], 2, [
            'recipient_email' => 'gifts@example.com',
            'gift_card_brand' => 'visa',
        ])
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.status', GiftCardRewardClaim::STATUS_SUBMITTED)
            ->assertJsonPath('data.gift_card_reward.gift_card_brand', 'visa')
            ->assertJsonPath('data.gift_card_reward.reward_amount', 80);

        $claim = GiftCardRewardClaim::findOrFail($response->json('data.gift_card_reward.id'));
        $this->assertSame($response->json('data.session_id'), $claim->session_id);
        $this->assertSame([$response->json('data.orders.0.order_id')], $claim->order_ids);
        $this->assertSame(800.0, $claim->order_total);

        Queue::assertPushed(SendAdminGiftCardRewardNotificationJob::class, fn ($job) => $job->claim_id === $claim->id);
    }

    public function test_pay_later_without_details_leaves_a_pending_claim(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->payLater([$coupon->id])
            ->assertOk()
            ->assertJsonPath('data.gift_card_reward.status', GiftCardRewardClaim::STATUS_PENDING_DETAILS)
            ->assertJsonPath('data.gift_card_reward.is_claimable', true);

        $this->assertSame(1, $coupon->fresh()->times_used);
        Queue::assertNotPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_pay_later_rejects_combining_the_promo_with_another_code(): void
    {
        $this->payLater([$this->makeRewardCoupon()->id, $this->makeRegularCoupon()->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This promo cannot be combined with other offers.');

        $this->assertSame(0, GiftCardRewardClaim::count());
    }

    public function test_pay_later_rejects_an_invalid_brand(): void
    {
        $this->payLater([$this->makeRewardCoupon()->id], 1, [
            'recipient_email' => 'gifts@example.com',
            'gift_card_brand' => 'walmart',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gift_card_reward.gift_card_brand');
    }
}
