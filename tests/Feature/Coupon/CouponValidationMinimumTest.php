<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/coupons/validate
 *
 * Regression coverage: promo codes used to be blocked for every cart under
 * $500.00. Promo codes must apply at any cart total, and a minimum is only
 * enforced when an individual coupon is configured with "minimum_purchase".
 */
class CouponValidationMinimumTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'client'], [
            'display_name' => 'Client',
            'description'  => 'Regular client',
        ]);

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'                    => 'CONF25',
            'name'                    => 'Conference 25',
            'discount_type'           => 'percentage',
            'discount_value'          => 25.0,
            'applies_to'              => 'all',
            'is_active'               => true,
            'times_used'              => 0,
            'usage_limit'             => null,
            'usage_per_user'          => null,
            'minimum_purchase_amount' => null,
            'starts_at'               => null,
            'expires_at'              => null,
            'product_types'           => null,
        ], $overrides));
    }

    private function validateCode(string $code, float $order_amount, string $product_type = 'link_building'): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'                 => $code,
            'order_amount'         => $order_amount,
            'cart_product_types'   => [$product_type],
            'product_type_amounts' => [$product_type => $order_amount],
        ]);
    }

    // ─── No global minimum ────────────────────────────────────────────────────

    public function test_coupon_without_minimum_applies_to_cart_under_500(): void
    {
        $this->makeCoupon();

        $response = $this->validateCode('CONF25', 400.0);

        $response->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('code', 'CONF25');
        $this->assertEquals(100.0, (float) $response->json('discount_amount'));
    }

    public function test_coupon_without_minimum_applies_to_a_very_small_cart(): void
    {
        $this->makeCoupon(['code' => 'TINY10', 'discount_value' => 10.0]);

        $response = $this->validateCode('TINY10', 50.0);

        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertEquals(5.0, (float) $response->json('discount_amount'));
    }

    public function test_cart_just_under_the_old_500_threshold_is_accepted(): void
    {
        $this->makeCoupon();

        $response = $this->validateCode('CONF25', 499.99);

        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertEquals(125.0, round((float) $response->json('discount_amount'), 2));
    }

    public function test_fixed_amount_coupon_is_capped_at_a_small_cart_total(): void
    {
        $this->makeCoupon([
            'code'           => 'FLAT300',
            'discount_type'  => 'fixed_amount',
            'discount_value' => 300.0,
        ]);

        $response = $this->validateCode('FLAT300', 200.0);

        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertEquals(200.0, (float) $response->json('discount_amount'));
    }

    public function test_product_type_coupon_applies_to_small_cart_of_that_product(): void
    {
        $this->makeCoupon([
            'code'          => 'CONTENT20',
            'discount_value' => 20.0,
            'product_types' => ['new_content'],
        ]);

        $response = $this->validateCode('CONTENT20', 150.0, 'new_content');

        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertEquals(30.0, (float) $response->json('discount_amount'));
    }

    public function test_lowercase_code_is_normalized_for_small_cart(): void
    {
        $this->makeCoupon();

        $this->validateCode('  conf25 ', 400.0)
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('code', 'CONF25');
    }

    // ─── Per coupon minimum still enforced ────────────────────────────────────

    public function test_coupon_with_its_own_minimum_is_rejected_below_that_minimum(): void
    {
        $this->makeCoupon([
            'code'                    => 'BIGORDER',
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 1000.0,
        ]);

        $response = $this->validateCode('BIGORDER', 400.0);

        $response->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('message', 'This coupon requires a minimum purchase of $1,000.00.');
    }

    public function test_coupon_with_its_own_minimum_applies_once_minimum_is_met(): void
    {
        $this->makeCoupon([
            'code'                    => 'SMALLMIN',
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 200.0,
        ]);

        $response = $this->validateCode('SMALLMIN', 400.0);

        $response->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('minimum_purchase_amount', 200);
        $this->assertEquals(100.0, (float) $response->json('discount_amount'));
    }

    public function test_coupon_with_its_own_minimum_accepts_cart_exactly_at_minimum(): void
    {
        $this->makeCoupon([
            'code'                    => 'EXACT',
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 300.0,
        ]);

        $this->validateCode('EXACT', 300.0)
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    // ─── Other rules are not affected by removing the global minimum ──────────

    public function test_unknown_code_is_still_rejected_for_small_cart(): void
    {
        $this->validateCode('NOPE', 400.0)
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'This promo code is expired or does not exist.');
    }

    public function test_inactive_code_is_still_rejected_for_small_cart(): void
    {
        $this->makeCoupon(['is_active' => false]);

        $this->validateCode('CONF25', 400.0)
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_product_type_restriction_is_still_enforced_for_small_cart(): void
    {
        $this->makeCoupon([
            'code'          => 'CONTENTONLY',
            'product_types' => ['new_content'],
        ]);

        $this->validateCode('CONTENTONLY', 400.0, 'link_building')
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'This coupon is only valid for: New Content.');
    }

    public function test_zero_order_amount_does_not_error(): void
    {
        $this->makeCoupon();

        $response = $this->validateCode('CONF25', 0.0);

        $response->assertOk()->assertJsonPath('valid', true);
        $this->assertEquals(0.0, (float) $response->json('discount_amount'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->makeCoupon();

        $this->postJson('/api/coupons/validate', [
            'code'         => 'CONF25',
            'order_amount' => 400.0,
        ])->assertUnauthorized();
    }
}
