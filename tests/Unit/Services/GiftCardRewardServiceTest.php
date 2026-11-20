<?php

namespace Tests\Unit\Services;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Models\Coupon;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use App\Services\CouponService;
use App\Services\GiftCardRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * GiftCardRewardService: splitting coupons at checkout, building claims,
 * submitting details and deciding when the client can still fill the form.
 */
class GiftCardRewardServiceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private GiftCardRewardService $service;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client  = $this->makeUserWithRole('client');
        $this->service = app(GiftCardRewardService::class);
    }

    private function orderEntry(string $product_type, float $total_amount): array
    {
        return [
            'product_type' => $product_type,
            'order_id'     => (string) Str::uuid(),
            'total_amount' => $total_amount,
        ];
    }

    // ─── splitRewardCoupon ────────────────────────────────────────────────────

    public function test_split_separates_the_reward_coupon_from_discount_coupons(): void
    {
        $reward  = $this->makeRewardCoupon();
        $regular = $this->makeRegularCoupon();

        [$discount_coupons, $reward_coupon] = $this->service->splitRewardCoupon([
            'a' => $regular,
            'b' => $reward,
        ]);

        $this->assertSame(['a'], array_keys($discount_coupons));
        $this->assertTrue($reward_coupon->is($reward));
    }

    public function test_split_returns_no_reward_coupon_for_regular_coupons_only(): void
    {
        [$discount_coupons, $reward_coupon] = $this->service->splitRewardCoupon([$this->makeRegularCoupon()]);

        $this->assertCount(1, $discount_coupons);
        $this->assertNull($reward_coupon);
    }

    // ─── findCombinationError ─────────────────────────────────────────────────

    public function test_a_single_reward_coupon_is_allowed(): void
    {
        $this->assertNull($this->service->findCombinationError([$this->makeRewardCoupon()->id]));
    }

    public function test_the_same_coupon_twice_counts_as_one(): void
    {
        $reward = $this->makeRewardCoupon();

        $this->assertNull($this->service->findCombinationError([$reward->id, $reward->id]));
    }

    public function test_reward_coupon_cannot_be_combined_with_another_coupon(): void
    {
        $error = $this->service->findCombinationError([
            $this->makeRewardCoupon()->id,
            $this->makeRegularCoupon()->id,
        ]);

        $this->assertSame('The HAPPYHOLIDAYS promo code cannot be combined with other promo codes.', $error);
    }

    public function test_two_regular_coupons_are_not_blocked_by_the_reward_rule(): void
    {
        $this->assertNull($this->service->findCombinationError([
            $this->makeRegularCoupon()->id,
            $this->makeRegularCoupon(['code' => 'SAVE20'])->id,
        ]));
    }

    // ─── detailsFromCheckout ──────────────────────────────────────────────────

    public function test_details_from_checkout_ignores_non_array_payloads(): void
    {
        $this->assertNull($this->service->detailsFromCheckout(null));
        $this->assertNull($this->service->detailsFromCheckout('amazon'));
    }

    public function test_details_from_checkout_fills_missing_keys_with_null(): void
    {
        $this->assertSame(
            ['recipient_email' => 'me@example.com', 'gift_card_brand' => null],
            $this->service->detailsFromCheckout(['recipient_email' => 'me@example.com'])
        );
    }

    // ─── createClaimForCheckout ───────────────────────────────────────────────

    public function test_complete_details_create_a_submitted_claim_and_notify_the_team(): void
    {
        $coupon = $this->makeRewardCoupon();

        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $coupon,
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
            ['recipient_email' => '  Gifts@Example.COM ', 'gift_card_brand' => 'visa'],
        );

        $this->assertSame(GiftCardRewardClaim::STATUS_SUBMITTED, $claim->status);
        $this->assertSame(GiftCardRewardClaim::SOURCE_CHECKOUT, $claim->submission_source);
        $this->assertSame('gifts@example.com', $claim->recipient_email);
        $this->assertSame('visa', $claim->gift_card_brand);
        $this->assertSame(80.0, $claim->reward_amount);
        $this->assertNotNull($claim->submitted_at);
        $this->assertSame(1, $coupon->fresh()->times_used);

        Queue::assertPushed(
            SendAdminGiftCardRewardNotificationJob::class,
            fn ($job) => $job->claim_id === $claim->id
        );
    }

    public function test_incomplete_details_create_a_pending_claim_without_notifying(): void
    {
        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $this->makeRewardCoupon(),
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
            ['recipient_email' => 'not-an-email', 'gift_card_brand' => 'visa'],
        );

        $this->assertSame(GiftCardRewardClaim::STATUS_PENDING_DETAILS, $claim->status);
        $this->assertNull($claim->recipient_email);
        $this->assertNull($claim->gift_card_brand);
        $this->assertNull($claim->submission_source);
        Queue::assertNotPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_unknown_brand_is_treated_as_incomplete(): void
    {
        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $this->makeRewardCoupon(),
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
            ['recipient_email' => 'me@example.com', 'gift_card_brand' => 'walmart'],
        );

        $this->assertSame(GiftCardRewardClaim::STATUS_PENDING_DETAILS, $claim->status);
    }

    public function test_reward_only_counts_the_product_types_the_coupon_covers(): void
    {
        $coupon = $this->makeRewardCoupon([
            'applies_to'    => 'all',
            'product_types' => ['link_building'],
        ]);

        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $coupon,
            (string) Str::uuid(),
            [
                $this->orderEntry('link_building', 600.0),
                $this->orderEntry('new_content', 400.0),
            ],
        );

        $this->assertSame(600.0, $claim->order_total);
        $this->assertSame(60.0, $claim->reward_amount);
        $this->assertCount(2, $claim->order_ids);
        $this->assertEqualsCanonicalizing(['link_building', 'new_content'], $claim->product_types);
    }

    public function test_reward_amount_is_rounded_to_cents(): void
    {
        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $this->makeRewardCoupon(['discount_value' => 12.5]),
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 333.33)],
        );

        $this->assertSame(41.67, $claim->reward_amount);
        $this->assertSame(12.5, $claim->reward_percentage);
    }

    public function test_no_claim_is_created_for_an_expired_promo(): void
    {
        $coupon = $this->makeRewardCoupon(['expires_at' => now()->subDay()]);

        $claim = $this->service->createClaimForCheckout(
            $this->client,
            $coupon,
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
        );

        $this->assertNull($claim);
        $this->assertSame(0, GiftCardRewardClaim::count());
        $this->assertSame(0, $coupon->fresh()->times_used);
    }

    public function test_no_claim_is_created_without_orders_or_for_regular_coupons(): void
    {
        $this->assertNull($this->service->createClaimForCheckout(
            $this->client,
            $this->makeRewardCoupon(),
            (string) Str::uuid(),
            [],
        ));

        $this->assertNull($this->service->createClaimForCheckout(
            $this->client,
            $this->makeRegularCoupon(),
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
        ));

        $this->assertSame(0, GiftCardRewardClaim::count());
    }

    // ─── safelyCreateClaimForCheckout ─────────────────────────────────────────

    public function test_safe_variant_returns_null_without_a_coupon(): void
    {
        $this->assertNull($this->service->safelyCreateClaimForCheckout(
            $this->client,
            null,
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
        ));
    }

    public function test_safe_variant_never_breaks_the_checkout_when_something_fails(): void
    {
        $coupon_service = Mockery::mock(CouponService::class);
        $coupon_service->shouldReceive('validateAndCalculate')->andThrow(new RuntimeException('Database is down'));
        $service = new GiftCardRewardService($coupon_service);

        $claim = $service->safelyCreateClaimForCheckout(
            $this->client,
            $this->makeRewardCoupon(),
            (string) Str::uuid(),
            [$this->orderEntry('link_building', 800.0)],
            ['recipient_email' => 'me@example.com', 'gift_card_brand' => 'amazon'],
        );

        $this->assertNull($claim);
        $this->assertSame(0, GiftCardRewardClaim::count());
    }

    // ─── submitDetails / isClaimable ──────────────────────────────────────────

    public function test_submit_details_normalizes_the_email_and_notifies_the_team(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $updated = $this->service->submitDetails($claim, ' Me@Example.com ', 'target');

        $this->assertSame('me@example.com', $updated->recipient_email);
        $this->assertSame('target', $updated->gift_card_brand);
        $this->assertSame(GiftCardRewardClaim::SOURCE_ORDER_PAGE, $updated->submission_source);
        $this->assertSame(GiftCardRewardClaim::STATUS_SUBMITTED, $updated->status);
        Queue::assertPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_pending_claim_is_claimable_while_the_promo_runs(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->assertTrue($this->service->isClaimable($claim));
    }

    public function test_claim_is_not_claimable_once_details_were_submitted(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        $this->assertFalse($this->service->isClaimable($claim));
    }

    public function test_claim_is_not_claimable_after_the_promo_ends(): void
    {
        $expired  = $this->makeClaim($this->client, $this->makeRewardCoupon(['expires_at' => now()->subMinute()]));
        $inactive = $this->makeClaim($this->client, $this->makeRewardCoupon(['code' => 'OLDPROMO', 'is_active' => false]));

        $this->assertFalse($this->service->isClaimable($expired));
        $this->assertFalse($this->service->isClaimable($inactive));
    }

    public function test_claim_is_not_claimable_when_the_coupon_was_deleted(): void
    {
        $coupon = $this->makeRewardCoupon();
        $claim  = $this->makeClaim($this->client, $coupon);
        $coupon->delete();

        $this->assertFalse($this->service->isClaimable($claim->fresh()));
    }

    // ─── Formatting ───────────────────────────────────────────────────────────

    public function test_client_format_includes_the_three_brand_options(): void
    {
        $data = $this->service->formatForClient($this->makeClaim($this->client, $this->makeRewardCoupon()));

        $this->assertSame('Happy Holidays 10% Back', $data['promotion_name']);
        $this->assertTrue($data['is_claimable']);
        $this->assertSame(['amazon', 'visa', 'target'], array_column($data['brands'], 'value'));
        $this->assertSame(['Amazon', 'Visa', 'Target'], array_column($data['brands'], 'label'));
    }

    public function test_admin_format_adds_client_and_brand_label(): void
    {
        $data = $this->service->formatForAdmin(
            $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon(), ['gift_card_brand' => 'target'])
        );

        $this->assertSame('Target', $data['gift_card_brand_label']);
        $this->assertSame($this->client->email, $data['client']['email']);
        $this->assertSame(GiftCardRewardClaim::SOURCE_CHECKOUT, $data['submission_source']);
        $this->assertNull($data['fulfilled_by']);
    }
}
