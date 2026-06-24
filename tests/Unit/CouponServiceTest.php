<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private CouponService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CouponService();
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'                    => 'TEST10',
            'name'                    => 'Test 10%',
            'discount_type'           => 'percentage',
            'discount_value'          => 10.0,
            'applies_to'              => 'all',
            'is_active'               => true,
            'times_used'              => 0,
            'usage_limit'             => null,
            'usage_per_user'          => null,
            'minimum_purchase_amount' => null,
            'starts_at'               => null,
            'expires_at'              => null,
        ], $overrides));
    }

    public function test_valid_percentage_coupon_calculates_discount(): void
    {
        $coupon = $this->makeCoupon(['discount_type' => 'percentage', 'discount_value' => 10.0]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertTrue($result['valid']);
        $this->assertEquals(100.0, $result['discount_amount']);
    }

    public function test_valid_fixed_coupon_calculates_discount(): void
    {
        $coupon = $this->makeCoupon(['discount_type' => 'fixed', 'discount_value' => 50.0]);

        $result = $this->service->validateAndCalculate($coupon, 600.0, 1);

        $this->assertTrue($result['valid']);
        $this->assertEquals(50.0, $result['discount_amount']);
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $coupon = $this->makeCoupon(['is_active' => false]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertFalse($result['valid']);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $coupon = $this->makeCoupon(['expires_at' => now()->subDay()]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('expired', strtolower($result['message']));
    }

    public function test_coupon_not_yet_started_is_rejected(): void
    {
        $coupon = $this->makeCoupon(['starts_at' => now()->addDay()]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not yet active', strtolower($result['message']));
    }

    public function test_coupon_at_global_usage_limit_is_rejected(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 5, 'times_used' => 5]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('usage limit', strtolower($result['message']));
    }

    public function test_coupon_under_usage_limit_is_accepted(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 10, 'times_used' => 9]);

        $result = $this->service->validateAndCalculate($coupon, 1000.0, 1);

        $this->assertTrue($result['valid']);
    }

    public function test_percentage_discount_does_not_exceed_order_amount(): void
    {
        $coupon = $this->makeCoupon(['discount_type' => 'percentage', 'discount_value' => 100.0]);

        $result = $this->service->validateAndCalculate($coupon, 300.0, 1);

        $this->assertTrue($result['valid']);
        $this->assertEquals(300.0, $result['discount_amount']);
    }

    public function test_fixed_discount_does_not_exceed_order_amount(): void
    {
        $coupon = $this->makeCoupon(['discount_type' => 'fixed', 'discount_value' => 500.0]);

        $result = $this->service->validateAndCalculate($coupon, 100.0, 1);

        $this->assertTrue($result['valid']);
        $this->assertLessThanOrEqual(100.0, $result['discount_amount']);
    }

    public function test_coupon_with_minimum_purchase_amount_rejects_below_threshold(): void
    {
        // minimum_purchase_amount check is only enforced when applies_to === 'minimum_purchase'
        $coupon = $this->makeCoupon([
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 500.0,
        ]);

        $result = $this->service->validateAndCalculate($coupon, 499.0, 1);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('minimum purchase', strtolower($result['message']));
    }

    public function test_coupon_with_minimum_purchase_amount_accepts_at_threshold(): void
    {
        $coupon = $this->makeCoupon([
            'applies_to'              => 'minimum_purchase',
            'minimum_purchase_amount' => 500.0,
        ]);

        $result = $this->service->validateAndCalculate($coupon, 500.0, 1);

        $this->assertTrue($result['valid']);
    }
}
