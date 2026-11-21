<?php

namespace Tests\Feature\Admin\SeoPackages;

use App\Models\SeoPackage;
use App\Models\SeoSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting an SEO package from the admin packages page.
 * DELETE /api/admin/seo-packages/{id}
 *
 * A package with open subscriptions (pending, active or processing) is
 * protected with a 409. Cancelled subscriptions do not block deletion.
 */
class AdminSeoPackageDeleteTest extends TestCase
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

    private function makePackage(array $overrides = []): SeoPackage
    {
        return SeoPackage::create(array_merge([
            'id'              => 'growth',
            'name'            => 'Growth',
            'slug'            => 'growth',
            'price_per_month' => 2500,
            'best_for'        => 'Growing brands',
            'features'        => [],
            'is_active'       => true,
        ], $overrides));
    }

    private function subscribe(SeoPackage $package, string $status): void
    {
        SeoSubscription::create([
            'user_id'      => $this->makeUserWithRole('client')->id,
            'package_id'   => $package->id,
            'status'       => $status,
            'total_amount' => 2500,
        ]);
    }

    public function test_admin_can_delete_a_package_without_subscriptions(): void
    {
        $package = $this->makePackage();

        $this->deleteAs($this->admin, "/api/admin/seo-packages/{$package->id}")->assertNoContent();

        $this->assertDatabaseMissing('seo_packages', ['id' => $package->id]);
    }

    /** @return array<string, array{0: string}> */
    public static function openSubscriptionStatusProvider(): array
    {
        return [
            'pending'    => ['pending'],
            'active'     => ['active'],
            'processing' => ['processing'],
        ];
    }

    #[DataProvider('openSubscriptionStatusProvider')]
    public function test_package_with_open_subscriptions_cannot_be_deleted(string $status): void
    {
        $package = $this->makePackage();
        $this->subscribe($package, $status);

        $this->deleteAs($this->admin, "/api/admin/seo-packages/{$package->id}")
            ->assertStatus(409)
            ->assertJson(['message' => 'Cannot delete a package with active subscriptions.']);

        $this->assertDatabaseHas('seo_packages', ['id' => $package->id]);
    }

    public function test_package_with_only_cancelled_subscriptions_can_be_deleted(): void
    {
        $package = $this->makePackage();
        $this->subscribe($package, 'cancelled');

        $this->deleteAs($this->admin, "/api/admin/seo-packages/{$package->id}")->assertNoContent();

        $this->assertDatabaseMissing('seo_packages', ['id' => $package->id]);
    }

    public function test_deleting_a_missing_package_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/seo-packages/missing')
            ->assertNotFound()
            ->assertJson(['message' => 'SEO package not found.']);
    }

    public function test_staff_cannot_delete_a_package(): void
    {
        $package = $this->makePackage();

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/seo-packages/{$package->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('seo_packages', ['id' => $package->id]);
    }

    public function test_guest_cannot_delete_a_package(): void
    {
        $package = $this->makePackage();

        $this->deleteJson("/api/admin/seo-packages/{$package->id}")->assertUnauthorized();

        $this->assertDatabaseHas('seo_packages', ['id' => $package->id]);
    }
}
