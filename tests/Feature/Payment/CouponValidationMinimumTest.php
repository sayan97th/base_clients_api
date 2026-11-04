<?php

namespace Tests\Feature\Payment;

use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
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

    private function validateCode(string $code, float $order_amount)
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'                 => $code,
            'order_amount'         => $order_amount,
            'cart_product_types'   => ['link_building'],
            'product_type_amounts' => ['link_building' => $order_amount],
        ]);
    }

    public function test_coupon_without_minimum_applies_to_cart_under_500(): void
    {
        $this->makeCoupon();

        $response = $this->validateCode('CONF25', 400.0);

        $response->assertOk()
            ->assertJsonPath('valid', true);
        $this->assertEquals(100.0, (float) $response->json('discount_amount'));
    }

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
            ->assertJsonPath('valid', true);
    }
}
