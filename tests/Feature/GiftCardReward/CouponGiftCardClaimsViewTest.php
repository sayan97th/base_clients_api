<?php

namespace Tests\Feature\GiftCardReward;

use App\Models\GiftCardRewardClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Dedicated admin view of one coupon gift card requests:
 *   GET /api/admin/coupons/{id}/gift-card-claims
 */
class CouponGiftCardClaimsViewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $admin;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->admin  = $this->makeUserWithRole('admin');
        $this->client = $this->makeUserWithRole('client');
    }

    private function getView(string $coupon_id, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'api')
            ->getJson('/api/admin/coupons/' . $coupon_id . '/gift-card-claims');
    }

    public function test_clients_cannot_open_the_view(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->getView($coupon->id, $this->client)->assertForbidden();
    }

    public function test_staff_and_super_admins_can_open_the_view(): void
    {
        $coupon = $this->makeRewardCoupon();
        $this->makeSubmittedClaim($this->client, $coupon);

        foreach (['staff', 'super_admin'] as $role) {
            $this->getView($coupon->id, $this->makeUserWithRole($role))
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_it_returns_the_coupon_its_requests_and_a_summary(): void
    {
        $coupon       = $this->makeRewardCoupon();
        $other_coupon = $this->makeRewardCoupon(['code' => 'SPRINGBACK']);
        $other_client = $this->makeUserWithRole('client');

        $pending = $this->makeClaim($this->client, $coupon, ['reward_amount' => 20.0, 'order_total' => 200.0]);
        $pending->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->makeSubmittedClaim($this->client, $coupon, ['reward_amount' => 30.0, 'order_total' => 300.0]);
        $this->makeSubmittedClaim($other_client, $coupon, [
            'gift_card_brand' => 'visa',
            'reward_amount'   => 50.0,
            'order_total'     => 500.0,
            'status'          => GiftCardRewardClaim::STATUS_FULFILLED,
            'fulfilled_at'    => now(),
            'fulfilled_by'    => $this->admin->id,
        ]);
        $this->makeSubmittedClaim($this->client, $other_coupon);

        $this->getView($coupon->id)
            ->assertOk()
            ->assertJsonPath('coupon.id', $coupon->id)
            ->assertJsonPath('coupon.code', 'HAPPYHOLIDAYS')
            ->assertJsonPath('coupon.gift_card_claims_count', 3)
            ->assertJsonPath('coupon.gift_card_claims_awaiting_count', 1)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.id', $pending->id)
            ->assertJsonPath('summary.total_count', 3)
            ->assertJsonPath('summary.client_count', 2)
            ->assertJsonPath('summary.reward_total', 100)
            ->assertJsonPath('summary.order_total', 1000)
            ->assertJsonPath('summary.by_status.pending_details.count', 1)
            ->assertJsonPath('summary.by_status.submitted.count', 1)
            ->assertJsonPath('summary.by_status.submitted.reward_total', 30)
            ->assertJsonPath('summary.by_status.fulfilled.count', 1)
            ->assertJsonPath('summary.by_status.fulfilled.reward_total', 50)
            ->assertJsonCount(2, 'summary.by_brand')
            ->assertJsonPath('data.0.fulfilled_by.id', $this->admin->id);
    }

    public function test_it_is_not_capped_like_the_generic_list(): void
    {
        $coupon = $this->makeRewardCoupon();

        for ($index = 0; $index < 501; $index++) {
            $this->makeClaim($this->client, $coupon);
        }

        $this->getView($coupon->id)
            ->assertOk()
            ->assertJsonCount(501, 'data')
            ->assertJsonPath('summary.total_count', 501);
    }

    public function test_an_empty_coupon_returns_an_empty_summary(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->getView($coupon->id)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.total_count', 0)
            ->assertJsonPath('summary.reward_total', 0)
            ->assertJsonPath('summary.first_created_at', null);
    }

    public function test_regular_coupons_are_rejected(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->getView($coupon->id)->assertStatus(422);
    }

    public function test_unknown_coupons_return_not_found(): void
    {
        $this->getView((string) Str::uuid())->assertNotFound();
    }
}
