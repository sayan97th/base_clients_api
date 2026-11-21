<?php

namespace Tests\Feature\GiftCardReward;

use App\Models\Coupon;
use App\Models\User;
use Database\Seeders\HolidayPromoCouponSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\GiftCardReward\InteractsWithGiftCardRewards;
use Tests\TestCase;

/**
 * The HAPPYHOLIDAYS promo requested by the client: 10% of the order total
 * back as an Amazon, Visa or Target gift card, valid from November 27
 * through December 18, 2026 (both days included, promotion timezone).
 */
class HolidayPromoCouponSeederTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithGiftCardRewards;

    private const TIMEZONE = 'America/Denver';

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.promotion_timezone' => self::TIMEZONE]);

        $this->seedGiftCardRewardRoles();
        $this->client = $this->makeUserWithRole('client');
    }

    private function seedPromo(): Coupon
    {
        $this->seed(HolidayPromoCouponSeeder::class);

        return Coupon::where('code', HolidayPromoCouponSeeder::CODE)->firstOrFail();
    }

    /**
     * Moves the clock to a wall clock time in the promotion timezone. The test
     * clock is kept in UTC, like the server, because Carbon reads stored dates
     * in the timezone of the test clock.
     */
    private function travelToLocal(string $local_time): void
    {
        $this->travelTo(Carbon::parse($local_time, self::TIMEZONE)->utc());
    }

    private function validatePromo(float $order_amount = 1000.0): TestResponse
    {
        return $this->actingAs($this->client, 'api')->postJson('/api/coupons/validate', [
            'code'                 => 'happyholidays',
            'order_amount'         => $order_amount,
            'cart_product_types'   => ['link_building'],
            'product_type_amounts' => ['link_building' => $order_amount],
        ])->assertOk();
    }

    // ── Seeded data ───────────────────────────────────────────────────────────

    public function test_seeder_creates_the_promo_with_the_requested_settings(): void
    {
        $coupon = $this->seedPromo();

        $this->assertSame('HAPPYHOLIDAYS', $coupon->code);
        $this->assertSame(Coupon::DISCOUNT_TYPE_GIFT_CARD_REWARD, $coupon->discount_type);
        $this->assertEquals(10.0, $coupon->discount_value);
        $this->assertSame(Coupon::APPLIES_TO_ALL, $coupon->applies_to);
        $this->assertNull($coupon->minimum_purchase_amount);
        $this->assertNull($coupon->usage_limit);
        $this->assertNull($coupon->usage_per_user);
        $this->assertTrue($coupon->is_active);
        $this->assertSame(0, $coupon->times_used);
    }

    public function test_seeder_stores_november_27_through_december_18(): void
    {
        $coupon = $this->seedPromo();

        $this->assertSame('2026-11-27', $coupon->startDate());
        $this->assertSame('2026-12-18', $coupon->endDate());

        // Midnight and 23:59:59 in Denver (UTC-7 in winter), stored in UTC.
        $this->assertSame('2026-11-27 07:00:00', $coupon->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-19 06:59:59', $coupon->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_admin_sees_the_first_and_last_day_of_the_promo(): void
    {
        $coupon = $this->seedPromo();
        $admin  = $this->makeUserWithRole('admin');

        $this->actingAs($admin, 'api')
            ->getJson("/api/admin/coupons/{$coupon->id}")
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-11-27')
            ->assertJsonPath('data.expires_at', '2026-12-18');
    }

    public function test_rerunning_the_seeder_keeps_usage_and_the_active_switch(): void
    {
        $coupon = $this->seedPromo();
        $coupon->update(['times_used' => 7, 'is_active' => false, 'discount_value' => 25]);

        $coupon = $this->seedPromo();

        $this->assertSame(1, Coupon::where('code', 'HAPPYHOLIDAYS')->count());
        $this->assertSame(7, $coupon->times_used);
        $this->assertFalse($coupon->is_active);
        $this->assertEquals(10.0, $coupon->discount_value);
    }

    public function test_seeder_restores_a_deleted_promo(): void
    {
        $this->seedPromo()->delete();

        $coupon = $this->seedPromo();

        $this->assertFalse($coupon->trashed());
    }

    // ── Promotion dates at checkout ───────────────────────────────────────────

    public function test_code_is_not_valid_before_november_27(): void
    {
        $this->seedPromo();
        $this->travelToLocal('2026-11-26 23:59:59');

        $this->validatePromo()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'This coupon is not yet active.');
    }

    public function test_code_works_from_the_first_minute_of_november_27(): void
    {
        $this->seedPromo();
        $this->travelToLocal('2026-11-27 00:00:00');

        $this->validatePromo(1000.0)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('is_gift_card_reward', true)
            ->assertJsonPath('discount_amount', 0)
            ->assertJsonPath('reward_amount', 100)
            ->assertJsonPath('gift_card_brands.0.value', 'amazon')
            ->assertJsonPath('gift_card_brands.1.value', 'visa')
            ->assertJsonPath('gift_card_brands.2.value', 'target');
    }

    public function test_code_works_in_the_middle_of_the_promo(): void
    {
        $this->seedPromo();
        $this->travelToLocal('2026-12-05 14:30:00');

        $this->validatePromo()->assertJsonPath('valid', true);
    }

    public function test_code_works_until_the_end_of_december_18(): void
    {
        $this->seedPromo();

        $this->travelToLocal('2026-12-18 08:00:00');
        $this->validatePromo()->assertJsonPath('valid', true);

        $this->travelToLocal('2026-12-18 23:59:58');
        $this->validatePromo()->assertJsonPath('valid', true);
    }

    public function test_code_stops_working_on_december_19(): void
    {
        $this->seedPromo();
        $this->travelToLocal('2026-12-19 00:00:00');

        $this->validatePromo()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('message', 'This coupon has expired.');
    }
}
