<?php

namespace Tests\Feature\Admin\ProductTier;

use App\Models\ContentBriefOrder;
use App\Models\ContentBriefTier;
use App\Models\ContentOptimizationOrder;
use App\Models\ContentOptimizationTier;
use App\Models\ContentRefreshTier;
use App\Models\DrTier;
use App\Models\NewContentTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting product tiers from the admin catalog pages.
 *
 * DELETE /api/admin/content-brief-tiers/{id}
 * DELETE /api/admin/content-optimization-tiers/{id}
 * DELETE /api/admin/new-content-tiers/{id}
 * DELETE /api/admin/content-refresh-tiers/{id}
 * DELETE /api/admin/dr-tiers/{id}
 *
 * Tier deletion is limited to super admins and admins. Tiers that are still
 * referenced by orders are protected with a 409 so order history stays valid.
 */
class AdminProductTierDeleteTest extends TestCase
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

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function makeContentBriefTier(): ContentBriefTier
    {
        return ContentBriefTier::create([
            'id'              => 'brief-standard',
            'label'           => 'Standard Brief',
            'turnaround_days' => 3,
            'price'           => 150,
            'is_active'       => true,
        ]);
    }

    private function makeContentOptimizationTier(): ContentOptimizationTier
    {
        return ContentOptimizationTier::create([
            'id'               => 'optimization-1000',
            'label'            => 'Up to 1,000 words',
            'word_count_range' => '0-1000',
            'turnaround_days'  => 5,
            'price'            => 200,
            'is_active'        => true,
        ]);
    }

    private function makeNewContentTier(): NewContentTier
    {
        return NewContentTier::create([
            'id'              => 'article-500',
            'label'           => '500 Word Optimized SEO Article',
            'turnaround_time' => '6 Business Days',
            'price'           => 120,
            'is_active'       => true,
        ]);
    }

    private function makeContentRefreshTier(): ContentRefreshTier
    {
        return ContentRefreshTier::create([
            'id'               => 'refresh-1500',
            'label'            => 'Refresh up to 1,500 words',
            'word_count_range' => '1000-1500',
            'turnaround_days'  => 4,
            'price'            => 180,
            'is_active'        => true,
        ]);
    }

    private function makeDrTier(): DrTier
    {
        return DrTier::create([
            'id'             => 'dr50',
            'label'          => 'DR 50+',
            'min_dr'         => 50,
            'max_dr'         => 59,
            'traffic_range'  => '10k-20k',
            'word_count'     => 500,
            'price_per_link' => 400.0,
            'is_hidden'      => false,
            'is_active'      => true,
        ]);
    }

    private function attachOrderItem(string $items_table, string $order_id, string $tier_id): void
    {
        DB::table($items_table)->insert([
            'id'         => (string) Str::uuid(),
            'order_id'   => $order_id,
            'tier_id'    => $tier_id,
            'quantity'   => 1,
            'unit_price' => 100,
            'subtotal'   => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderAttributes(User $client): array
    {
        return [
            'user_id'                  => $client->id,
            'order_title'              => 'Test order',
            'order_notes'              => null,
            'subtotal_before_discount' => 100.0,
            'total_amount'             => 100.0,
            'status'                   => 'processing',
            'payment_intent_id'        => 'pi_test_' . Str::random(8),
        ];
    }

    // ── Content brief tiers ───────────────────────────────────────────────────

    public function test_admin_can_delete_a_content_brief_tier(): void
    {
        $tier = $this->makeContentBriefTier();

        $this->deleteAs($this->admin, "/api/admin/content-brief-tiers/{$tier->id}")->assertNoContent();

        $this->assertDatabaseMissing('content_brief_tiers', ['id' => $tier->id]);
    }

    public function test_content_brief_tier_used_by_an_order_cannot_be_deleted(): void
    {
        $tier = $this->makeContentBriefTier();
        $order = ContentBriefOrder::create($this->orderAttributes($this->makeUserWithRole('client')));
        $this->attachOrderItem('content_brief_order_items', $order->id, $tier->id);

        $this->deleteAs($this->admin, "/api/admin/content-brief-tiers/{$tier->id}")
            ->assertStatus(409)
            ->assertJson([
                'message' => 'This tier cannot be deleted because it is referenced by existing orders.',
            ]);

        $this->assertDatabaseHas('content_brief_tiers', ['id' => $tier->id]);
    }

    public function test_deleting_a_missing_content_brief_tier_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/content-brief-tiers/missing')
            ->assertNotFound()
            ->assertJson(['message' => 'Content brief tier not found.']);
    }

    // ── Content optimization tiers ────────────────────────────────────────────

    public function test_admin_can_delete_a_content_optimization_tier(): void
    {
        $tier = $this->makeContentOptimizationTier();

        $this->deleteAs($this->admin, "/api/admin/content-optimization-tiers/{$tier->id}")->assertNoContent();

        $this->assertDatabaseMissing('content_optimization_tiers', ['id' => $tier->id]);
    }

    public function test_content_optimization_tier_used_by_an_order_cannot_be_deleted(): void
    {
        $tier = $this->makeContentOptimizationTier();
        $order = ContentOptimizationOrder::create($this->orderAttributes($this->makeUserWithRole('client')));
        $this->attachOrderItem('content_optimization_order_items', $order->id, $tier->id);

        $this->deleteAs($this->admin, "/api/admin/content-optimization-tiers/{$tier->id}")
            ->assertStatus(409)
            ->assertJson([
                'message' => 'This tier cannot be deleted because it is associated with existing orders.',
            ]);

        $this->assertDatabaseHas('content_optimization_tiers', ['id' => $tier->id]);
    }

    public function test_deleting_a_missing_content_optimization_tier_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/content-optimization-tiers/missing')
            ->assertNotFound()
            ->assertJson(['message' => 'Content optimization tier not found.']);
    }

    // ── New content tiers ─────────────────────────────────────────────────────

    public function test_admin_can_delete_a_new_content_tier(): void
    {
        $tier = $this->makeNewContentTier();

        $this->deleteAs($this->admin, "/api/admin/new-content-tiers/{$tier->id}")->assertNoContent();

        $this->assertDatabaseMissing('new_content_tiers', ['id' => $tier->id]);
    }

    public function test_deleting_a_missing_new_content_tier_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/new-content-tiers/missing')
            ->assertNotFound()
            ->assertJson(['message' => 'New content tier not found.']);
    }

    // ── Content refresh tiers ─────────────────────────────────────────────────

    public function test_admin_can_delete_a_content_refresh_tier(): void
    {
        $tier = $this->makeContentRefreshTier();

        $this->deleteAs($this->admin, "/api/admin/content-refresh-tiers/{$tier->id}")->assertNoContent();

        $this->assertDatabaseMissing('content_refresh_tiers', ['id' => $tier->id]);
    }

    public function test_deleting_a_missing_content_refresh_tier_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/content-refresh-tiers/missing')->assertNotFound();
    }

    // ── DR tiers ──────────────────────────────────────────────────────────────

    public function test_removing_a_dr_tier_soft_deletes_it(): void
    {
        $tier = $this->makeDrTier();

        $this->deleteAs($this->admin, "/api/admin/dr-tiers/{$tier->id}")->assertNoContent();

        // The admin dialog says the tier is marked as unavailable, the row stays for order history.
        $this->assertSoftDeleted('dr_tiers', ['id' => $tier->id]);
    }

    public function test_deleting_a_missing_dr_tier_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/dr-tiers/missing')
            ->assertNotFound()
            ->assertJson(['message' => 'DR Tier not found.']);
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function test_super_admin_can_delete_a_tier(): void
    {
        $tier = $this->makeNewContentTier();

        $this->deleteAs($this->makeUserWithRole('super_admin'), "/api/admin/new-content-tiers/{$tier->id}")
            ->assertNoContent();
    }

    /** @return array<string, array{0: string}> */
    public static function tierEndpointProvider(): array
    {
        return [
            'content brief'        => ['content-brief-tiers'],
            'content optimization' => ['content-optimization-tiers'],
            'new content'          => ['new-content-tiers'],
            'content refresh'      => ['content-refresh-tiers'],
            'dr tier'              => ['dr-tiers'],
        ];
    }

    #[DataProvider('tierEndpointProvider')]
    public function test_staff_cannot_delete_tiers(string $endpoint): void
    {
        $tier_id = $this->makeTierFor($endpoint);

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/{$endpoint}/{$tier_id}")
            ->assertForbidden();

        $this->assertTierStillExists($endpoint, $tier_id);
    }

    #[DataProvider('tierEndpointProvider')]
    public function test_client_cannot_delete_tiers(string $endpoint): void
    {
        $tier_id = $this->makeTierFor($endpoint);

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/{$endpoint}/{$tier_id}")
            ->assertForbidden();

        $this->assertTierStillExists($endpoint, $tier_id);
    }

    #[DataProvider('tierEndpointProvider')]
    public function test_guest_cannot_delete_tiers(string $endpoint): void
    {
        $tier_id = $this->makeTierFor($endpoint);

        $this->deleteJson("/api/admin/{$endpoint}/{$tier_id}")->assertUnauthorized();

        $this->assertTierStillExists($endpoint, $tier_id);
    }

    private function makeTierFor(string $endpoint): string
    {
        return match ($endpoint) {
            'content-brief-tiers'        => $this->makeContentBriefTier()->id,
            'content-optimization-tiers' => $this->makeContentOptimizationTier()->id,
            'new-content-tiers'          => $this->makeNewContentTier()->id,
            'content-refresh-tiers'      => $this->makeContentRefreshTier()->id,
            'dr-tiers'                   => $this->makeDrTier()->id,
        };
    }

    private function assertTierStillExists(string $endpoint, string $tier_id): void
    {
        $table = match ($endpoint) {
            'content-brief-tiers'        => 'content_brief_tiers',
            'content-optimization-tiers' => 'content_optimization_tiers',
            'new-content-tiers'          => 'new_content_tiers',
            'content-refresh-tiers'      => 'content_refresh_tiers',
            'dr-tiers'                   => 'dr_tiers',
        };

        // DR tiers use soft deletes, so "still exists" also means "not trashed".
        $conditions = $table === 'dr_tiers'
            ? ['id' => $tier_id, 'deleted_at' => null]
            : ['id' => $tier_id];

        $this->assertDatabaseHas($table, $conditions);
    }
}
