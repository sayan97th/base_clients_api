<?php

namespace Tests\Feature\Coupon;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coupons saved before calendar day dates existed keep the day the admin
 * picked, and that day is now included in the promotion.
 */
class NormalizeCouponDatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.promotion_timezone' => 'America/Denver']);

        $this->migration = require database_path('migrations/2026_11_20_000001_normalize_coupon_dates_to_promotion_calendar_days.php');
    }

    private function insertCoupon(?string $starts_at, ?string $expires_at): string
    {
        $id = (string) Str::uuid();

        DB::table('coupons')->insert([
            'id'             => $id,
            'code'           => strtoupper(Str::random(8)),
            'name'           => 'Legacy coupon',
            'discount_type'  => 'percentage',
            'discount_value' => 10,
            'applies_to'     => 'all',
            'starts_at'      => $starts_at,
            'expires_at'     => $expires_at,
            'times_used'     => 0,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $id;
    }

    private function storedDates(string $id): array
    {
        $row = DB::table('coupons')->where('id', $id)->first();

        return [
            $row->starts_at ? substr($row->starts_at, 0, 19) : null,
            $row->expires_at ? substr($row->expires_at, 0, 19) : null,
        ];
    }

    public function test_date_only_values_become_full_calendar_days(): void
    {
        $id = $this->insertCoupon('2026-11-27 00:00:00', '2026-12-18 00:00:00');

        $this->migration->up();

        $this->assertSame(['2026-11-27 07:00:00', '2026-12-19 06:59:59'], $this->storedDates($id));
    }

    public function test_exact_times_and_empty_dates_are_left_alone(): void
    {
        $id = $this->insertCoupon(null, '2026-12-31 23:59:59');

        $this->migration->up();

        $this->assertSame([null, '2026-12-31 23:59:59'], $this->storedDates($id));
    }

    public function test_running_it_twice_changes_nothing_more(): void
    {
        $id = $this->insertCoupon('2026-11-27 00:00:00', '2026-12-18 00:00:00');

        $this->migration->up();
        $this->migration->up();

        $this->assertSame(['2026-11-27 07:00:00', '2026-12-19 06:59:59'], $this->storedDates($id));
    }

    public function test_down_restores_the_previous_format(): void
    {
        $id = $this->insertCoupon('2026-11-27 00:00:00', '2026-12-18 00:00:00');

        $this->migration->up();
        $this->migration->down();

        $this->assertSame(['2026-11-27 00:00:00', '2026-12-18 00:00:00'], $this->storedDates($id));
    }
}
