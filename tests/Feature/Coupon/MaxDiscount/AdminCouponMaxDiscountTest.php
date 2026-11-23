<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Setting the per order limit from Admin > Coupons (New Coupon and Edit
 * Coupon pages).
 *
 *   POST  /api/admin/coupons
 *   PATCH /api/admin/coupons/{id}
 *   GET   /api/admin/coupons and /api/admin/coupons/{id}
 */
class AdminCouponMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedGiftCardRewardRoles();
        $this->admin = $this->makeUserWithRole('admin');
    }

    /** Mirrors the payload the New Coupon page sends for a gift card coupon. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code'                    => 'BIGBACK',
            'name'                    => 'Big Back',
            'description'             => null,
            'discount_type'           => Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD,
            'discount_value'          => 10,
            'applies_to'              => Coupon::APPLIES_TO_ALL,
            'product_types'           => [],
            'dr_tier_ids'             => [],
            'minimum_purchase_amount' => null,
            'max_discount_amount'     => 1000,
            'starts_at'               => null,
            'expires_at'              => null,
            'usage_limit'             => null,
            'usage_per_user'          => null,
            'is_active'               => true,
        ], $overrides);
    }

    private function store(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'api')->postJson('/api/admin/coupons', $this->payload($overrides));
    }

    private function update(Coupon $coupon, array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'api')->patchJson("/api/admin/coupons/{$coupon->id}", $payload);
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_admin_creates_a_gift_card_coupon_with_a_limit(): void
    {
        $this->store()
            ->assertCreated()
            ->assertJsonPath('data.discount_type', Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD)
            ->assertJsonPath('data.max_discount_amount', 1000);

        $this->assertDatabaseHas('coupons', ['code' => 'BIGBACK', 'max_discount_amount' => 1000]);
    }

    public function test_admin_creates_a_percentage_coupon_with_a_limit(): void
    {
        $this->store(['code' => 'SAVE20', 'discount_type' => Coupon::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => 20, 'max_discount_amount' => 300])
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', 300);
    }

    public function test_the_limit_is_optional(): void
    {
        $this->store(['max_discount_amount' => null])
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', null);

        $payload = $this->payload(['code' => 'NOLIMIT']);
        unset($payload['max_discount_amount']);

        $this->actingAs($this->admin, 'api')
            ->postJson('/api/admin/coupons', $payload)
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_the_limit_keeps_cents(): void
    {
        $this->store(['max_discount_amount' => 999.99])
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', 999.99);
    }

    public function test_fixed_amount_coupons_never_store_a_limit(): void
    {
        $this->store(['discount_type' => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 'discount_value' => 100, 'max_discount_amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.max_discount_amount', null);

        $this->assertNull(Coupon::where('code', 'BIGBACK')->value('max_discount_amount'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidLimits(): array
    {
        return [
            'zero'       => [0, 'The maximum amount must be greater than 0.'],
            'negative'   => [-100, 'The maximum amount must be greater than 0.'],
            'text'       => ['one thousand', 'The maximum amount must be a valid dollar amount.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function test_an_invalid_limit_is_rejected_on_create(mixed $value, string $message): void
    {
        $this->store(['max_discount_amount' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_discount_amount' => $message]);

        $this->assertDatabaseMissing('coupons', ['code' => 'BIGBACK']);
    }

    public function test_an_absurdly_large_limit_is_rejected(): void
    {
        $this->store(['max_discount_amount' => 1000000000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_discount_amount');
    }

    public function test_clients_cannot_create_coupons_with_a_limit(): void
    {
        $this->store([], $this->makeUserWithRole('client'))->assertForbidden();

        $this->assertDatabaseMissing('coupons', ['code' => 'BIGBACK']);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function test_admin_adds_a_limit_to_an_existing_coupon(): void
    {
        $coupon = $this->makeRewardCoupon();

        $this->update($coupon, ['max_discount_amount' => 1000])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1000);

        $this->assertEquals(1000.0, $coupon->fresh()->max_discount_amount);
    }

    public function test_admin_changes_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, ['max_discount_amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1500);
    }

    public function test_admin_removes_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, ['max_discount_amount' => null])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', null);

        $this->assertNull($coupon->fresh()->max_discount_amount);
    }

    public function test_saving_the_whole_edit_form_keeps_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, $this->payload(['code' => 'HAPPYHOLIDAYS', 'name' => 'Happy Holidays 10% Back']))
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1000);
    }

    public function test_editing_other_fields_keeps_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, ['name' => 'Renamed promo', 'discount_value' => 15])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1000);
    }

    public function test_switching_to_fixed_amount_clears_the_limit(): void
    {
        $coupon = $this->makeRegularCoupon(['max_discount_amount' => 300.0]);

        $this->update($coupon, ['discount_type' => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 'discount_value' => 50])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_a_limit_sent_for_a_stored_fixed_amount_coupon_is_ignored(): void
    {
        $coupon = $this->makeRegularCoupon(['discount_type' => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 'discount_value' => 50]);

        $this->update($coupon, ['max_discount_amount' => 25])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', null);
    }

    public function test_switching_from_fixed_amount_to_percentage_accepts_a_limit(): void
    {
        $coupon = $this->makeRegularCoupon(['discount_type' => Coupon::DISCOUNT_TYPE_FIXED_AMOUNT, 'discount_value' => 50]);

        $this->update($coupon, ['discount_type' => Coupon::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => 20, 'max_discount_amount' => 300])
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 300);
    }

    public function test_an_invalid_limit_is_rejected_on_edit_and_nothing_changes(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, ['max_discount_amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_discount_amount');

        $this->assertEquals(1000.0, $coupon->fresh()->max_discount_amount);
    }

    public function test_the_quick_status_switch_keeps_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->actingAs($this->admin, 'api')
            ->patchJson("/api/admin/coupons/{$coupon->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.max_discount_amount', 1000);
    }

    public function test_clients_cannot_change_the_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);

        $this->update($coupon, ['max_discount_amount' => 999999], $this->makeUserWithRole('client'))->assertForbidden();

        $this->assertEquals(1000.0, $coupon->fresh()->max_discount_amount);
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    public function test_the_list_and_the_detail_include_the_limit(): void
    {
        $capped    = $this->makeRewardCoupon(['max_discount_amount' => 1000.0]);
        $unlimited = $this->makeRegularCoupon();

        $list = $this->actingAs($this->admin, 'api')->getJson('/api/admin/coupons')->assertOk();
        $by_id = collect($list->json('data'))->keyBy('id');

        $this->assertEquals(1000, $by_id[$capped->id]['max_discount_amount']);
        $this->assertNull($by_id[$unlimited->id]['max_discount_amount']);

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/coupons/{$capped->id}")
            ->assertOk()
            ->assertJsonPath('data.max_discount_amount', 1000);
    }
}
