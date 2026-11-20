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
 * Admin board of promo gift card requests:
 *   GET   /api/admin/gift-card-claims
 *   PATCH /api/admin/gift-card-claims/{id}/fulfillment
 */
class GiftCardRewardAdminApiTest extends TestCase
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

    private function markFulfilled(string $id, bool $is_fulfilled, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'api')
            ->patchJson('/api/admin/gift-card-claims/' . $id . '/fulfillment', ['is_fulfilled' => $is_fulfilled]);
    }

    // ─── Access ───────────────────────────────────────────────────────────────

    public function test_clients_cannot_see_or_update_claims(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        $this->actingAs($this->client, 'api')->getJson('/api/admin/gift-card-claims')->assertForbidden();
        $this->markFulfilled($claim->id, true, $this->client)->assertForbidden();

        $this->assertSame(GiftCardRewardClaim::STATUS_SUBMITTED, $claim->fresh()->status);
    }

    public function test_staff_and_super_admins_can_see_claims(): void
    {
        $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        foreach (['staff', 'super_admin'] as $role) {
            $this->actingAs($this->makeUserWithRole($role), 'api')
                ->getJson('/api/admin/gift-card-claims')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    // ─── Listing ──────────────────────────────────────────────────────────────

    public function test_claims_are_listed_newest_first_with_client_details(): void
    {
        $coupon = $this->makeRewardCoupon();
        $older  = $this->makeSubmittedClaim($this->client, $coupon);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer  = $this->makeClaim($this->client, $coupon);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.recipient_email', 'gifts@example.com')
            ->assertJsonPath('data.1.gift_card_brand_label', 'Amazon')
            ->assertJsonPath('data.1.client.email', $this->client->email);
    }

    public function test_claims_can_be_filtered_by_status_coupon_session_and_order(): void
    {
        $holiday     = $this->makeRewardCoupon();
        $other       = $this->makeRewardCoupon(['code' => 'SPRINGBACK']);
        $pending     = $this->makeClaim($this->client, $holiday);
        $submitted   = $this->makeSubmittedClaim($this->client, $holiday);
        $other_claim = $this->makeSubmittedClaim($this->client, $other);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims?status=' . GiftCardRewardClaim::STATUS_PENDING_DETAILS)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims?coupon_id=' . $other->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $other_claim->id);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims?session_id=' . $submitted->session_id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $submitted->id);

        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims?order_id=' . $submitted->order_ids[0])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $submitted->id);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/gift-card-claims?status=lost&coupon_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'coupon_id']);
    }

    // ─── Fulfillment ──────────────────────────────────────────────────────────

    public function test_admin_marks_a_gift_card_as_sent_and_can_undo_it(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        $this->markFulfilled($claim->id, true)
            ->assertOk()
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_FULFILLED)
            ->assertJsonPath('data.fulfilled_by.id', $this->admin->id);

        $this->assertNotNull($claim->fresh()->fulfilled_at);

        $this->markFulfilled($claim->id, false)
            ->assertOk()
            ->assertJsonPath('data.status', GiftCardRewardClaim::STATUS_SUBMITTED)
            ->assertJsonPath('data.fulfilled_at', null)
            ->assertJsonPath('data.fulfilled_by', null);
    }

    public function test_pending_claims_cannot_be_marked_as_sent(): void
    {
        $claim = $this->makeClaim($this->client, $this->makeRewardCoupon());

        $this->markFulfilled($claim->id, true)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The client has not submitted the gift card details yet.');

        $this->assertSame(GiftCardRewardClaim::STATUS_PENDING_DETAILS, $claim->fresh()->status);
    }

    public function test_fulfillment_requires_a_boolean_and_a_known_claim(): void
    {
        $claim = $this->makeSubmittedClaim($this->client, $this->makeRewardCoupon());

        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/gift-card-claims/' . $claim->id . '/fulfillment', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_fulfilled');

        $this->markFulfilled('not-a-uuid', true)->assertNotFound();
        $this->markFulfilled((string) Str::uuid(), true)->assertNotFound();
    }
}
