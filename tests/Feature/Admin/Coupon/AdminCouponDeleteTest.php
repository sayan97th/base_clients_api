<?php

namespace Tests\Feature\Admin\Coupon;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting a coupon from the admin coupons list. DELETE /api/admin/coupons/{id}
 *
 * Coupons use soft deletes, so a deleted code disappears from the admin list
 * and can no longer be redeemed, while past orders keep their reference.
 */
class AdminCouponDeleteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminRoles;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAdminRoles();
        $this->admin = $this->makeUserWithRole('admin');
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'           => 'SUMMER25',
            'name'           => 'Summer Sale',
            'discount_type'  => 'percentage',
            'discount_value' => 25.0,
            'applies_to'     => 'all',
            'is_active'      => true,
            'times_used'     => 0,
        ], $overrides));
    }

    public function test_admin_can_delete_a_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->deleteAs($this->admin, "/api/admin/coupons/{$coupon->id}")->assertNoContent();

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_deleted_coupon_is_no_longer_listed(): void
    {
        $kept = $this->makeCoupon(['code' => 'KEEPME']);
        $deleted = $this->makeCoupon(['code' => 'DELETEME']);

        $this->deleteAs($this->admin, "/api/admin/coupons/{$deleted->id}")->assertNoContent();

        $codes = collect(
            $this->actingAs($this->admin, 'api')->getJson('/api/admin/coupons')->assertOk()->json('data')
        )->pluck('code');

        $this->assertTrue($codes->contains($kept->code));
        $this->assertFalse($codes->contains($deleted->code));
    }

    public function test_staff_can_delete_a_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/coupons/{$coupon->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_deleting_a_missing_coupon_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/coupons/does-not-exist')
            ->assertNotFound()
            ->assertJson(['message' => 'Coupon not found.']);
    }

    public function test_deleting_an_already_deleted_coupon_returns_not_found(): void
    {
        $coupon = $this->makeCoupon();
        $coupon->delete();

        $this->deleteAs($this->admin, "/api/admin/coupons/{$coupon->id}")->assertNotFound();
    }

    public function test_client_cannot_delete_a_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/coupons/{$coupon->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_guest_cannot_delete_a_coupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->deleteJson("/api/admin/coupons/{$coupon->id}")->assertUnauthorized();

        $this->assertNotSoftDeleted('coupons', ['id' => $coupon->id]);
    }
}
