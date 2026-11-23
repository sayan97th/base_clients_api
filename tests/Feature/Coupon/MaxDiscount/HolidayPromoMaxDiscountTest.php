<?php

namespace Tests\Feature\Coupon\MaxDiscount;

use App\Models\Coupon;
use App\Services\CouponService;
use Database\Seeders\HolidayPromoCouponSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * How the $1,000 limit reaches the HAPPYHOLIDAYS coupon:
 *
 *   new environments     HolidayPromoCouponSeeder creates it with the limit
 *   environments where   the migration 2026_11_23_000003 adds the limit to
 *   it already exists    the live coupon without touching anything else
 */
class HolidayPromoMaxDiscountTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private const BACKFILL_MIGRATION = 'database/migrations/2026_11_23_000003_apply_gift_card_cap_to_holiday_promo_coupon.php';

    private function backfillMigration(): Migration
    {
        return require base_path(self::BACKFILL_MIGRATION);
    }

    private function holidayCoupon(): Coupon
    {
        return Coupon::where('code', HolidayPromoCouponSeeder::CODE)->firstOrFail();
    }

    // ── Seeder ───────────────────────────────────────────────────────────────

    public function test_the_limit_constant_is_one_thousand_dollars(): void
    {
        $this->assertSame(1000.00, HolidayPromoCouponSeeder::MAX_REWARD_AMOUNT);
    }

    public function test_the_seeder_creates_the_promo_with_the_limit(): void
    {
        $this->seed(HolidayPromoCouponSeeder::class);

        $coupon = $this->holidayCoupon();
        $this->assertEquals(1000.0, $coupon->max_discount_amount);
        $this->assertStringContainsString('up to $1,000 per order', $coupon->description);
    }

    public function test_the_seeded_promo_pays_ten_percent_up_to_one_thousand_dollars(): void
    {
        $this->seed(HolidayPromoCouponSeeder::class);

        $coupon  = $this->holidayCoupon();
        $service = new CouponService();

        $this->assertSame(800.0, $service->calculateRewardAmount($coupon, 8000.0));
        $this->assertSame(1000.0, $service->calculateRewardAmount($coupon, 20000.0));
        $this->assertSame(1000.0, $service->calculateRewardAmount($coupon, 50000.0));
    }

    public function test_re_running_the_seeder_adds_the_limit_to_an_existing_promo_and_keeps_usage(): void
    {
        $this->makeRewardCoupon(['max_discount_amount' => null, 'times_used' => 7, 'is_active' => false]);

        $this->seed(HolidayPromoCouponSeeder::class);

        $coupon = $this->holidayCoupon();
        $this->assertEquals(1000.0, $coupon->max_discount_amount);
        $this->assertSame(7, $coupon->times_used);
        $this->assertFalse($coupon->is_active);
    }

    // ── Backfill migration ───────────────────────────────────────────────────

    public function test_the_migration_adds_the_limit_to_the_live_promo(): void
    {
        $coupon = $this->makeRewardCoupon([
            'max_discount_amount' => null,
            'times_used'          => 12,
            'starts_at'           => '2026-11-27',
            'expires_at'          => '2026-12-18',
        ]);
        $starts_at  = $coupon->starts_at;
        $expires_at = $coupon->expires_at;

        $this->backfillMigration()->up();

        $coupon->refresh();
        $this->assertEquals(1000.0, $coupon->max_discount_amount);
        $this->assertSame(12, $coupon->times_used);
        $this->assertTrue($coupon->is_active);
        $this->assertTrue($coupon->starts_at->equalTo($starts_at));
        $this->assertTrue($coupon->expires_at->equalTo($expires_at));
    }

    public function test_the_migration_keeps_a_limit_an_admin_already_chose(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 750.0]);

        $this->backfillMigration()->up();

        $this->assertEquals(750.0, $coupon->fresh()->max_discount_amount);
    }

    public function test_the_migration_only_touches_happyholidays(): void
    {
        $other_reward = $this->makeRewardCoupon(['code' => 'SPRINGBACK']);
        $percentage   = $this->makeRegularCoupon();

        $this->backfillMigration()->up();

        $this->assertNull($other_reward->fresh()->max_discount_amount);
        $this->assertNull($percentage->fresh()->max_discount_amount);
    }

    public function test_the_migration_does_nothing_when_the_promo_does_not_exist(): void
    {
        $this->backfillMigration()->up();

        $this->assertSame(0, Coupon::count());
    }

    public function test_rolling_back_the_migration_removes_only_the_limit_it_added(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => null]);
        $migration = $this->backfillMigration();

        $migration->up();
        $migration->down();

        $this->assertNull($coupon->fresh()->max_discount_amount);
    }

    public function test_rolling_back_keeps_a_different_limit(): void
    {
        $coupon = $this->makeRewardCoupon(['max_discount_amount' => 750.0]);

        $this->backfillMigration()->down();

        $this->assertEquals(750.0, $coupon->fresh()->max_discount_amount);
    }
}
