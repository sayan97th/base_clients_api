<?php

namespace Tests\Feature\Admin\Discount;

use App\Models\Discount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting a bulk discount from the admin discounts page.
 * DELETE /api/admin/discounts/{id}
 */
class AdminDiscountDeleteTest extends TestCase
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

    private function makeDiscount(array $overrides = []): Discount
    {
        return Discount::create(array_merge([
            'name'          => 'Bulk 12+ links',
            'description'   => '10% off when ordering 12 links or more.',
            'discount_type' => 'bulk',
            'discount_rate' => 10.0,
            'min_quantity'  => 12,
            'applies_to'    => 'link_building',
            'is_active'     => true,
        ], $overrides));
    }

    public function test_admin_can_delete_a_discount(): void
    {
        $discount = $this->makeDiscount();

        $this->deleteAs($this->admin, "/api/admin/discounts/{$discount->id}")
            ->assertOk()
            ->assertJson(['message' => 'Discount deleted successfully.']);

        $this->assertDatabaseMissing('discounts', ['id' => $discount->id]);
    }

    public function test_only_the_selected_discount_is_deleted(): void
    {
        $kept = $this->makeDiscount(['name' => 'Keep me']);
        $deleted = $this->makeDiscount(['name' => 'Delete me']);

        $this->deleteAs($this->admin, "/api/admin/discounts/{$deleted->id}")->assertOk();

        $this->assertDatabaseHas('discounts', ['id' => $kept->id]);
        $this->assertDatabaseMissing('discounts', ['id' => $deleted->id]);
    }

    public function test_staff_can_delete_a_discount(): void
    {
        $discount = $this->makeDiscount();

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/discounts/{$discount->id}")
            ->assertOk();

        $this->assertDatabaseMissing('discounts', ['id' => $discount->id]);
    }

    public function test_deleting_a_missing_discount_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/discounts/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertJson(['message' => 'Discount not found.']);
    }

    public function test_client_cannot_delete_a_discount(): void
    {
        $discount = $this->makeDiscount();

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/discounts/{$discount->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('discounts', ['id' => $discount->id]);
    }

    public function test_guest_cannot_delete_a_discount(): void
    {
        $discount = $this->makeDiscount();

        $this->deleteJson("/api/admin/discounts/{$discount->id}")->assertUnauthorized();

        $this->assertDatabaseHas('discounts', ['id' => $discount->id]);
    }
}
