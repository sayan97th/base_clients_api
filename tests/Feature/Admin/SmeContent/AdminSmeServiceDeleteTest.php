<?php

namespace Tests\Feature\Admin\SmeContent;

use App\Models\SmeAuthoredTier;
use App\Models\SmeCollaborationTier;
use App\Models\SmeEnhancedTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting SME service tiers from the admin SME content page.
 *
 * DELETE /api/admin/sme-content/authored-services/{tier_key}
 * DELETE /api/admin/sme-content/collaboration-services/{tier_key}
 * DELETE /api/admin/sme-content/enhanced-services/{tier_key}
 *
 * The URL parameter is the tier_key, not the numeric id.
 */
class AdminSmeServiceDeleteTest extends TestCase
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

    /** @return array<string, array{0: string, 1: class-string<Model>, 2: string}> */
    public static function serviceProvider(): array
    {
        return [
            'authored'      => ['authored-services', SmeAuthoredTier::class, 'sme_authored_tiers'],
            'collaboration' => ['collaboration-services', SmeCollaborationTier::class, 'sme_collaboration_tiers'],
            'enhanced'      => ['enhanced-services', SmeEnhancedTier::class, 'sme_enhanced_tiers'],
        ];
    }

    /** @param class-string<Model> $model_class */
    private function makeService(string $model_class, string $tier_key): Model
    {
        return $model_class::create([
            'tier_key'    => $tier_key,
            'label'       => 'Expert interview',
            'description' => 'A 30 minute interview with a subject matter expert.',
            'price'       => 500,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
    }

    #[DataProvider('serviceProvider')]
    public function test_admin_can_delete_a_service_by_tier_key(string $endpoint, string $model_class, string $table): void
    {
        $deleted = $this->makeService($model_class, 'expert-interview');
        $kept = $this->makeService($model_class, 'expert-review');

        $this->deleteAs($this->admin, "/api/admin/sme-content/{$endpoint}/expert-interview")->assertNoContent();

        $this->assertDatabaseMissing($table, ['id' => $deleted->id]);
        $this->assertDatabaseHas($table, ['id' => $kept->id]);
    }

    #[DataProvider('serviceProvider')]
    public function test_staff_can_delete_a_service(string $endpoint, string $model_class, string $table): void
    {
        $this->makeService($model_class, 'expert-interview');

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/sme-content/{$endpoint}/expert-interview")
            ->assertNoContent();

        $this->assertDatabaseMissing($table, ['tier_key' => 'expert-interview']);
    }

    #[DataProvider('serviceProvider')]
    public function test_deleting_a_missing_service_returns_not_found(string $endpoint): void
    {
        $this->deleteAs($this->admin, "/api/admin/sme-content/{$endpoint}/missing")
            ->assertNotFound()
            ->assertJson(['message' => 'Service not found.']);
    }

    #[DataProvider('serviceProvider')]
    public function test_client_cannot_delete_a_service(string $endpoint, string $model_class, string $table): void
    {
        $this->makeService($model_class, 'expert-interview');

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/sme-content/{$endpoint}/expert-interview")
            ->assertForbidden();

        $this->assertDatabaseHas($table, ['tier_key' => 'expert-interview']);
    }

    #[DataProvider('serviceProvider')]
    public function test_guest_cannot_delete_a_service(string $endpoint, string $model_class, string $table): void
    {
        $this->makeService($model_class, 'expert-interview');

        $this->deleteJson("/api/admin/sme-content/{$endpoint}/expert-interview")->assertUnauthorized();

        $this->assertDatabaseHas($table, ['tier_key' => 'expert-interview']);
    }
}
