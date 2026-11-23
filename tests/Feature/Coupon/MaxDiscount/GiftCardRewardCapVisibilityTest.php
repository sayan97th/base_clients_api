<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * After checkout, everyone who looks at a capped gift card must understand
 * why it is $1,000 and not 10% of the order:
 *
 *   the client      GET /api/gift-card-rewards/lookup (order page, receipt)
 *   the admin team  GET /api/admin/coupons/{id}/gift-card-claims
 *                   GET /api/admin/gift-card-claims
 *   the admin email SendAdminGiftCardRewardNotificationJob
 */
class GiftCardRewardCapVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client', ['first_name' => 'Jane', 'last_name' => 'Client']);
        $this->admin  = $this->makeUserWithRole('admin', ['first_name' => 'Abby']);
    }

    /** A $50,000 HAPPYHOLIDAYS order: 10% would be $5,000, the limit sends $1,000. */
    private function makeCappedClaim(array $overrides = [])
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        return $this->makeSubmittedClaim($this->client, $coupon, array_merge([
            'order_total'       => 50000.0,
            'reward_amount'     => 1000.0,
            'max_reward_amount' => 1000.0,
        ], $overrides));
    }

    // ── Client ───────────────────────────────────────────────────────────────

    public function test_the_client_sees_the_limit_on_a_capped_gift_card(): void
    {
        $claim = $this->makeCappedClaim(['status' => 'pending_details', 'recipient_email' => null, 'gift_card_brand' => null]);

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.order_total', 50000)
            ->assertJsonPath('data.reward_amount', 1000)
            ->assertJsonPath('data.max_reward_amount', 1000)
            ->assertJsonPath('data.is_reward_capped', true);
    }

    public function test_a_gift_card_below_the_limit_is_not_flagged(): void
    {
        $claim = $this->makeCappedClaim(['order_total' => 800.0, 'reward_amount' => 80.0]);

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.max_reward_amount', 1000)
            ->assertJsonPath('data.is_reward_capped', false);
    }

    public function test_gift_cards_earned_before_the_limit_existed_are_unchanged(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon(), [
            'order_total'   => 50000.0,
            'reward_amount' => 5000.0,
        ]);

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.reward_amount', 5000)
            ->assertJsonPath('data.max_reward_amount', null)
            ->assertJsonPath('data.is_reward_capped', false);
    }

    // ── Admin views ──────────────────────────────────────────────────────────

    public function test_the_coupon_gift_card_view_shows_the_limit(): void
    {
        $claim = $this->makeCappedClaim();

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/coupons/' . $claim->coupon_id . '/gift-card-claims')
            ->assertOk()
            ->assertJsonPath('coupon.max_discount_amount', 1000)
            ->assertJsonPath('data.0.reward_amount', 1000)
            ->assertJsonPath('data.0.max_reward_amount', 1000)
            ->assertJsonPath('data.0.is_reward_capped', true)
            ->assertJsonPath('summary.reward_total', 1000);
    }

    public function test_the_gift_card_requests_list_shows_the_limit(): void
    {
        $claim = $this->makeCappedClaim();

        $response = $this->actingAs($this->admin, 'api')->getJson('/api/admin/gift-card-claims')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $claim->id);
        $this->assertNotNull($row);
        $this->assertEquals(1000, $row['max_reward_amount']);
        $this->assertTrue($row['is_reward_capped']);
    }

    // ── Admin email ──────────────────────────────────────────────────────────

    public function test_the_admin_email_explains_the_cap(): void
    {
        Bus::fake([SendEmailJob::class]);
        $claim = $this->makeCappedClaim();

        (new SendAdminGiftCardRewardNotificationJob($claim->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            $mail = $job->mailable;

            return $mail->reward_amount === '$1,000.00'
                && $mail->order_total === '$50,000.00'
                && $mail->reward_cap === '$1,000.00'
                && str_contains($mail->render(), '10% back on a $50,000.00 order, capped at $1,000.00');
        });
    }

    public function test_the_admin_email_has_no_cap_note_below_the_limit(): void
    {
        Bus::fake([SendEmailJob::class]);
        $claim = $this->makeCappedClaim(['order_total' => 800.0, 'reward_amount' => 80.0]);

        (new SendAdminGiftCardRewardNotificationJob($claim->id))->handle();

        Bus::assertDispatched(SendEmailJob::class, function (SendEmailJob $job) {
            return $job->mailable->reward_cap === ''
                && ! str_contains($job->mailable->render(), 'capped at');
        });
    }
}
