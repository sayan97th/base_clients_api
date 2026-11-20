<?php

namespace Tests\Feature\GiftCardReward;

use App\Jobs\SendAdminGiftCardRewardNotificationJob;
use App\Models\GiftCardRewardClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Client endpoints used by the order page when the client skipped the gift
 * card form at checkout:
 *   GET /api/gift-card-rewards/lookup
 *   PUT /api/gift-card-rewards/{id}
 */
class GiftCardRewardClientApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    private function submit(string $id, array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->client, 'api')->putJson('/api/gift-card-rewards/' . $id, $payload);
    }

    // ─── Lookup ───────────────────────────────────────────────────────────────

    public function test_guests_cannot_use_the_endpoints(): void
    {
        $this->getJson('/api/gift-card-rewards/lookup?session_id=abc')->assertUnauthorized();
        $this->putJson('/api/gift-card-rewards/' . Str::uuid(), [])->assertUnauthorized();
    }

    public function test_lookup_requires_a_session_or_an_order(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session_id', 'order_id']);
    }

    public function test_lookup_finds_the_claim_by_session_and_by_order(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.id', $claim->id)
            ->assertJsonPath('data.coupon_code', 'HAPPYHOLIDAYS')
            ->assertJsonPath('data.reward_amount', 80)
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_PENDING_DETAILS)
            ->assertJsonPath('data.is_claimable', true)
            ->assertJsonCount(3, 'data.brands');

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?order_id=' . $claim->order_ids[0])
            ->assertOk()
            ->assertJsonPath('data.id', $claim->id);
    }

    public function test_lookup_returns_null_when_the_order_did_not_use_the_promo(): void
    {
        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?order_id=' . Str::uuid())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_lookup_returns_null_for_a_non_uuid_order_id(): void
    {
        $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?order_id=not-a-uuid')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_lookup_never_shows_another_clients_reward(): void
    {
        $claim    = $this->makeClaim($this->client, $this->makeRewardCoupon());
        $intruder = $this->makeUserWithRole('client');

        $this->actingAs($intruder, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_submitted_rewards_stay_visible_after_the_promo_ends(): void
    {
        $coupon = $this->makeRewardCoupon();
        $claim  = $this->makeSubmittedClaim($this->client, $coupon);
        $coupon->update(['expires_at' => now()->subDay()]);

        $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.id', $claim->id)
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_SUBMITTED)
            ->assertJsonPath('data.is_claimable', false);
    }

    public function test_fulfilled_rewards_show_when_they_were_sent(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon(), [
            'status'       => GiftCardRewardClaim::STATUS_FULFILLED,
            'fulfilled_at' => now(),
        ]);

        $response = $this->actingAs($this->client, 'api')
            ->getJson('/api/gift-card-rewards/lookup?session_id=' . $claim->session_id)
            ->assertOk()
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_FULFILLED);

        $this->assertNotNull($response->json('data.fulfilled_at'));
    }

    // ─── Submit ───────────────────────────────────────────────────────────────

    public function test_client_submits_details_and_the_team_is_notified(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->submit($claim->id, [
            'recipient_email' => '  Gifts@Example.com ',
            'gift_card_brand' => 'amazon',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Thanks! Your gift card details were sent to our team.')
            ->assertJsonPath('data.recipient_email', 'gifts@example.com')
            ->assertJsonPath('data.gift_card_brand', 'amazon')
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_SUBMITTED);

        $this->assertDatabaseHas('gift_card_reward_claims', [
            'id'                => $claim->id,
            'submission_source' => GiftCardRewardClaim::SOURCE_ORDER_PAGE,
        ]);

        Queue::assertPushed(
            SendAdminGiftCardRewardNotificationJob::class,
            fn ($job) => $job->claim_id === $claim->id
        );
    }

    public function test_each_brand_can_be_chosen(): void
    {
        $coupon = $this->makeRewardCoupon();

        foreach (['amazon', 'visa', 'target'] as $brand) {
            $claim = $this->makeClaim($this->client, $coupon);

            $this->submit($claim->id, ['recipient_email' => 'me@example.com', 'gift_card_brand' => $brand])
                ->assertOk()
                ->assertJsonPath('data.gift_card_brand', $brand);
        }
    }

    public function test_both_fields_are_required_with_friendly_messages(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->submit($claim->id, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'recipient_email' => 'Please enter the email address where the gift card should be sent.',
                'gift_card_brand' => 'Please choose a gift card.',
            ]);

        Queue::assertNotPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->submit($claim->id, ['recipient_email' => 'not-an-email', 'gift_card_brand' => 'visa'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_email' => 'Please enter a valid email address.']);

        $this->assertSame(GiftCardRewardClaim::STATUS_PENDING_DETAILS, $claim->fresh()->status);
    }

    public function test_unknown_or_non_uuid_ids_return_not_found(): void
    {
        $payload = ['recipient_email' => 'me@example.com', 'gift_card_brand' => 'visa'];

        $this->submit('not-a-uuid', $payload)->assertNotFound();
        $this->submit((string) Str::uuid(), $payload)->assertNotFound();
    }

    public function test_details_cannot_be_changed_after_they_were_submitted(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        $this->submit($claim->id, ['recipient_email' => 'other@example.com', 'gift_card_brand' => 'visa'])
            ->assertStatus(409);

        $this->assertSame('gifts@example.com', $claim->fresh()->recipient_email);
        Queue::assertNotPushed(SendAdminGiftCardRewardNotificationJob::class);
    }

    public function test_details_cannot_be_submitted_after_the_promo_was_deleted(): void
    {
        $coupon = $this->makeRewardCoupon();
        $claim  = $this->makeClaim($this->client, $coupon);
        $coupon->delete();

        $this->submit($claim->id, ['recipient_email' => 'me@example.com', 'gift_card_brand' => 'visa'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This promotion has ended, so gift card details can no longer be submitted.');
    }
}
