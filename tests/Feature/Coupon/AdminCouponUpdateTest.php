<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Editing coupons from the admin portal. PATCH /api/admin/coupons/{id}
 * and the quick status switch PATCH /api/admin/coupons/{id}/status.
 */
class AdminCouponUpdateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $admin;

    private DrTier $dr_tier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->admin = $this->makeUserWithRole('admin');

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

    /** Mirrors the full payload the Edit Coupon page sends. */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'code'                    => 'TESTLAUNCH100',
            'name'                    => 'Test Launch Special',
            'description'             => '$100 off flat, testing high value fixed discount.',
            'discount_type'           => 'fixed_amount',
            'discount_value'          => 100,
            'applies_to'              => 'minimum_purchase',
            'product_types'           => [],
            'dr_tier_ids'             => [],
            'minimum_purchase_amount' => 800,
            'starts_at'               => null,
            'expires_at'              => now()->addYear()->format('Y-m-d'),
            'usage_limit'             => 50,
            'usage_per_user'          => null,
            'is_active'               => false,
        ], $overrides);
    }

    private function patchCoupon(Coupon $coupon, array $payload)
    {
        return $this->actingAs($this->admin, 'api')->patchJson("/api/admin/coupons/{$coupon->id}", $payload);
    }

    private function patchStatus(Coupon $coupon, mixed $is_active)
    {
        return $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", ['is_active' => $is_active]);
    }

    // ── Discount scope ────────────────────────────────────────────────────────

    public function test_minimum_purchase_coupon_saves_with_an_empty_dr_tier_list(): void
    {
        $coupon = $this->makeRegularCoupon(['code' => 'TESTLAUNCH100']);

        $this->patchCoupon($coupon, $this->editPayload())
            ->assertOk()
            ->assertJsonPath('data.applies_to', 'minimum_purchase')
            ->assertJsonPath('data.minimum_purchase_amount', 800)
            ->assertJsonPath('data.dr_tier_ids', []);
    }

    public function test_all_products_coupon_saves_with_an_empty_dr_tier_list(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchCoupon($coupon, $this->editPayload([
            'applies_to'              => 'all',
            'minimum_purchase_amount' => null,
        ]))->assertOk();
    }

    public function test_dr_tier_scope_still_requires_at_least_one_tier(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchCoupon($coupon, $this->editPayload([
            'applies_to'              => 'specific_product',
            'minimum_purchase_amount' => null,
        ]))->assertStatus(422)->assertJsonValidationErrors('dr_tier_ids');
    }

    public function test_leaving_the_dr_tier_scope_clears_the_stored_tiers(): void
    {
        $coupon = $this->makeRegularCoupon(['applies_to' => 'specific_product']);
        $coupon->drTiers()->sync([$this->dr_tier->id]);

        $this->patchCoupon($coupon, $this->editPayload(['dr_tier_ids' => [$this->dr_tier->id]]))
            ->assertOk()
            ->assertJsonPath('data.dr_tier_ids', []);

        $this->assertSame(0, $coupon->drTiers()->count());
    }

    public function test_leaving_the_minimum_purchase_scope_clears_the_minimum_amount(): void
    {
        $coupon = $this->makeRegularCoupon([
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 800,
        ]);

        $this->patchCoupon($coupon, ['applies_to' => 'all'])->assertOk();

        $this->assertNull($coupon->refresh()->minimum_purchase_amount);
    }

    public function test_an_expired_coupon_can_be_edited_without_changing_its_expiry_date(): void
    {
        // Admins pick calendar days, which are measured in the promotion timezone.
        $expired_day = now(Coupon::promotionTimezone())->subDays(3)->format('Y-m-d');
        $coupon      = $this->makeRegularCoupon(['expires_at' => $expired_day]);

        $this->patchCoupon($coupon, $this->editPayload([
            'expires_at' => $expired_day,
        ]))->assertOk();
    }

    public function test_a_new_expiry_date_cannot_be_in_the_past(): void
    {
        $coupon = $this->makeRegularCoupon(['expires_at' => now()->addMonth()]);

        $this->patchCoupon($coupon, $this->editPayload([
            'expires_at' => now(Coupon::promotionTimezone())->subDay()->format('Y-m-d'),
        ]))->assertStatus(422)->assertJsonValidationErrors('expires_at');
    }

    // ── Partial updates ───────────────────────────────────────────────────────

    public function test_a_partial_update_keeps_the_tiers_of_a_dr_tier_coupon(): void
    {
        $coupon = $this->makeRegularCoupon(['applies_to' => 'specific_product']);
        $coupon->drTiers()->sync([$this->dr_tier->id]);

        $this->patchCoupon($coupon, ['name' => 'Renamed'])->assertOk();

        $this->assertSame('Renamed', $coupon->refresh()->name);
        $this->assertSame(1, $coupon->drTiers()->count());
    }

    public function test_a_dr_tier_coupon_cannot_be_left_without_tiers(): void
    {
        $coupon = $this->makeRegularCoupon(['applies_to' => 'specific_product']);
        $coupon->drTiers()->sync([$this->dr_tier->id]);

        $this->patchCoupon($coupon, ['dr_tier_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dr_tier_ids');

        $this->assertSame(1, $coupon->drTiers()->count());
    }

    public function test_the_tiers_of_a_dr_tier_coupon_can_be_replaced(): void
    {
        $other_tier = DrTier::create([
            'id'             => 'dr60',
            'label'          => 'DR 60+',
            'min_dr'         => 60,
            'max_dr'         => 69,
            'traffic_range'  => '20k-40k',
            'word_count'     => 500,
            'price_per_link' => 600.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
        $coupon = $this->makeRegularCoupon(['applies_to' => 'specific_product']);
        $coupon->drTiers()->sync([$this->dr_tier->id]);

        $this->patchCoupon($coupon, ['dr_tier_ids' => [$other_tier->id]])
            ->assertOk()
            ->assertJsonPath('data.dr_tier_ids', [$other_tier->id]);
    }

    public function test_switching_to_the_minimum_purchase_scope_requires_an_amount(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchCoupon($coupon, ['applies_to' => 'minimum_purchase'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('minimum_purchase_amount');
    }

    public function test_the_generic_update_can_still_change_only_the_status(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchCoupon($coupon, ['is_active' => false])->assertOk();

        $this->assertFalse($coupon->refresh()->is_active);
    }

    public function test_a_percentage_above_100_is_rejected_using_the_stored_type(): void
    {
        $coupon = $this->makeRegularCoupon(['discount_type' => 'percentage']);

        $this->patchCoupon($coupon, ['discount_value' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    public function test_update_returns_not_found_for_an_unknown_coupon(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/coupons/00000000-0000-0000-0000-000000000000', ['name' => 'Ghost'])
            ->assertNotFound();
    }

    // ── Quick status switch ───────────────────────────────────────────────────

    public function test_admin_can_disable_a_coupon_from_the_status_endpoint(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchStatus($coupon, false)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($coupon->refresh()->is_active);
    }

    public function test_a_coupon_with_invalid_stored_data_can_still_be_disabled(): void
    {
        // DR Tiers scope without tiers and an old expiry date would fail the full edit.
        $coupon = $this->makeRegularCoupon([
            'applies_to' => 'specific_product',
            'expires_at' => now()->subMonth(),
        ]);

        $this->patchStatus($coupon, false)->assertOk();

        $this->assertFalse($coupon->refresh()->is_active);
    }

    public function test_status_switch_only_changes_the_status(): void
    {
        $coupon = $this->makeRegularCoupon(['is_active' => false, 'discount_value' => 15]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", [
                'is_active'      => true,
                'discount_value' => 99,
            ])
            ->assertOk();

        $coupon->refresh();
        $this->assertTrue($coupon->is_active);
        $this->assertEquals(15.0, $coupon->discount_value);
    }

    public function test_admin_can_enable_a_disabled_coupon_again(): void
    {
        $coupon = $this->makeRegularCoupon(['is_active' => false]);

        $this->patchStatus($coupon, true)->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_staff_can_disable_a_coupon(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->actingAs($this->makeUserWithRole('staff'), 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($coupon->refresh()->is_active);
    }

    public function test_status_switch_keeps_the_coupon_tiers_and_scope(): void
    {
        $coupon = $this->makeRegularCoupon(['applies_to' => 'specific_product']);
        $coupon->drTiers()->sync([$this->dr_tier->id]);

        $this->patchStatus($coupon, false)
            ->assertOk()
            ->assertJsonPath('data.applies_to', 'specific_product')
            ->assertJsonPath('data.dr_tier_ids', [$this->dr_tier->id]);
    }

    public function test_status_switch_returns_the_gift_card_counts(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->patchStatus($coupon, false)
            ->assertOk()
            ->assertJsonPath('data.is_gift_card_reward', true)
            ->assertJsonPath('data.gift_card_claims_count', 0);
    }

    public function test_a_deleted_coupon_cannot_be_switched(): void
    {
        $coupon = $this->makeRegularCoupon();
        $coupon->delete();

        $this->patchStatus($coupon, true)->assertNotFound();
    }

    public function test_status_switch_requires_is_active(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active' => 'Choose whether the coupon is active or disabled.']);
    }

    public function test_status_switch_requires_a_boolean(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->patchStatus($coupon, 'maybe')->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_status_switch_returns_not_found_for_an_unknown_coupon(): void
    {
        $this->actingAs($this->admin, 'api')
            ->patchJson('/api/admin/coupons/00000000-0000-0000-0000-000000000000/status', ['is_active' => false])
            ->assertNotFound();
    }

    public function test_clients_cannot_change_the_coupon_status(): void
    {
        $coupon = $this->makeRegularCoupon();

        $this->actingAs($this->makeUserWithRole('client'), 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($coupon->refresh()->is_active);
    }
}
