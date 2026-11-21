<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\DrTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * Creating coupons from the admin portal. POST /api/admin/coupons
 *
 * The discount scope (applies_to) decides which extra fields are required:
 * DR tiers for the DR Tiers scope and a minimum amount for the Minimum
 * Purchase scope. Fields that belong to another scope are ignored.
 */
class AdminCouponStoreTest extends TestCase
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

    /** Mirrors the payload the New Coupon page sends. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code'                    => 'launch100',
            'name'                    => 'Launch Special',
            'description'             => null,
            'discount_type'           => 'fixed_amount',
            'discount_value'          => 100,
            'applies_to'              => 'all',
            'product_types'           => [],
            'dr_tier_ids'             => [],
            'minimum_purchase_amount' => null,
            'starts_at'               => null,
            'expires_at'              => null,
            'usage_limit'             => null,
            'usage_per_user'          => null,
            'is_active'               => true,
        ], $overrides);
    }

    private function createCoupon(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'api')->postJson('/api/admin/coupons', $this->payload($overrides));
    }

    public function test_all_products_coupon_is_created_with_an_empty_dr_tier_list(): void
    {
        $this->createCoupon()
            ->assertCreated()
            ->assertJsonPath('data.code', 'LAUNCH100')
            ->assertJsonPath('data.applies_to', 'all')
            ->assertJsonPath('data.dr_tier_ids', []);
    }

    public function test_minimum_purchase_coupon_is_created_with_an_empty_dr_tier_list(): void
    {
        $this->createCoupon([
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 800,
        ])
            ->assertCreated()
            ->assertJsonPath('data.minimum_purchase_amount', 800);
    }

    public function test_minimum_purchase_scope_requires_an_amount(): void
    {
        $this->createCoupon(['applies_to' => 'minimum_purchase'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'minimum_purchase_amount' => 'A minimum purchase amount is required when the discount scope is Minimum Purchase.',
            ]);
    }

    public function test_dr_tier_scope_requires_at_least_one_tier(): void
    {
        $this->createCoupon(['applies_to' => 'specific_product'])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'dr_tier_ids' => 'At least one DR tier must be selected when the discount scope is DR Tiers.',
            ]);

        $this->assertSame(0, Coupon::count());
    }

    public function test_dr_tier_scope_stores_the_selected_tiers(): void
    {
        $response = $this->createCoupon([
            'applies_to'  => 'specific_product',
            'dr_tier_ids' => [$this->dr_tier->id],
        ])->assertCreated();

        $response->assertJsonPath('data.dr_tier_ids', [$this->dr_tier->id]);
        $this->assertSame(1, Coupon::firstOrFail()->drTiers()->count());
    }

    public function test_unknown_dr_tiers_are_rejected(): void
    {
        $this->createCoupon([
            'applies_to'  => 'specific_product',
            'dr_tier_ids' => ['missing-tier'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dr_tier_ids.0' => 'One or more selected DR tiers are invalid.']);
    }

    public function test_fields_from_another_scope_are_not_stored(): void
    {
        $this->createCoupon([
            'applies_to'              => 'all',
            'dr_tier_ids'             => [$this->dr_tier->id],
            'minimum_purchase_amount' => 500,
        ])->assertCreated();

        $coupon = Coupon::firstOrFail();
        $this->assertSame(0, $coupon->drTiers()->count());
        $this->assertNull($coupon->minimum_purchase_amount);
    }

    public function test_unknown_scope_is_rejected(): void
    {
        $this->createCoupon(['applies_to' => 'everything'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('applies_to');
    }

    public function test_percentage_cannot_exceed_100(): void
    {
        $this->createCoupon(['discount_type' => 'percentage', 'discount_value' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
    }

    public function test_fixed_amount_has_no_upper_limit(): void
    {
        $this->createCoupon(['discount_value' => 5000])->assertCreated();
    }

    public function test_codes_must_be_unique(): void
    {
        $this->createCoupon()->assertCreated();

        $this->createCoupon(['name' => 'Another'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_coupon_can_be_created_disabled(): void
    {
        $this->createCoupon(['is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_clients_cannot_create_coupons(): void
    {
        $this->actingAs($this->makeUserWithRole('client'), 'api')
            ->postJson('/api/admin/coupons', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Coupon::count());
    }

    // ── Validity dates ────────────────────────────────────────────────────────

    public function test_start_and_expiry_dates_are_saved_as_full_calendar_days(): void
    {
        config(['app.promotion_timezone' => 'America/Denver']);
        $this->travelTo(Carbon::parse('2026-11-20 18:00:00', 'UTC'));

        $this->createCoupon(['starts_at' => '2026-11-27', 'expires_at' => '2026-12-18'])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-27')
            ->assertJsonPath('data.expires_at', '2026-12-18');

        $coupon = Coupon::firstOrFail();
        $this->assertSame('2026-12-19 06:59:59', $coupon->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_coupon_can_start_and_expire_on_the_same_day(): void
    {
        $day = now(Coupon::promotionTimezone())->addWeek()->format('Y-m-d');

        $this->createCoupon(['starts_at' => $day, 'expires_at' => $day])->assertCreated();
    }

    public function test_the_start_date_cannot_be_after_the_expiry_date(): void
    {
        $expiry_day = now(Coupon::promotionTimezone())->addWeek();

        $this->createCoupon([
            'starts_at'  => $expiry_day->copy()->addDay()->format('Y-m-d'),
            'expires_at' => $expiry_day->format('Y-m-d'),
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }
}
