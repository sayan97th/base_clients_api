<?php

namespace Tests\Feature\Admin\PremiumMentions;

use App\Models\PremiumMentionsOrder;
use App\Models\PremiumMentionsPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting a Premium Mentions plan from the admin plans page.
 * DELETE /api/admin/premium-mentions/plans/{id}
 *
 * Plans with pending or processing orders are protected with a 409.
 */
class AdminPremiumMentionsPlanDeleteTest extends TestCase
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

    private function makePlan(array $overrides = []): PremiumMentionsPlan
    {
        return PremiumMentionsPlan::create(array_merge([
            'name'                 => 'Authority',
            'price_per_month'      => 4000,
            'total_placements'     => 10,
            'exclusive_placements' => 2,
            'core_placements'      => 4,
            'support_placements'   => 4,
            'best_for'             => 'Established brands',
            'tagline'              => 'Get featured where it matters',
            'is_active'            => true,
        ], $overrides));
    }

    private function placeOrder(PremiumMentionsPlan $plan, string $status): void
    {
        PremiumMentionsOrder::create([
            'client_id'    => $this->makeUserWithRole('client')->id,
            'plan_id'      => $plan->id,
            'total_amount' => 4000,
            'status'       => $status,
        ]);
    }

    public function test_admin_can_delete_a_plan_without_orders(): void
    {
        $plan = $this->makePlan();

        $this->deleteAs($this->admin, "/api/admin/premium-mentions/plans/{$plan->id}")
            ->assertOk()
            ->assertJson(['message' => 'Plan deleted successfully.']);

        $this->assertDatabaseMissing('premium_mentions_plans', ['id' => $plan->id]);
    }

    /** @return array<string, array{0: string}> */
    public static function openOrderStatusProvider(): array
    {
        return [
            'pending'    => ['pending'],
            'processing' => ['processing'],
        ];
    }

    #[DataProvider('openOrderStatusProvider')]
    public function test_plan_with_open_orders_cannot_be_deleted(string $status): void
    {
        $plan = $this->makePlan();
        $this->placeOrder($plan, $status);

        $this->deleteAs($this->admin, "/api/admin/premium-mentions/plans/{$plan->id}")
            ->assertStatus(409)
            ->assertJson(['message' => 'Cannot delete a plan with active orders. Disable it instead.']);

        $this->assertDatabaseHas('premium_mentions_plans', ['id' => $plan->id]);
    }

    public function test_plan_with_only_finished_orders_can_be_deleted(): void
    {
        $plan = $this->makePlan();
        $this->placeOrder($plan, 'completed');
        $this->placeOrder($plan, 'cancelled');

        $this->deleteAs($this->admin, "/api/admin/premium-mentions/plans/{$plan->id}")->assertOk();

        $this->assertDatabaseMissing('premium_mentions_plans', ['id' => $plan->id]);
    }

    public function test_deleting_a_missing_plan_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/premium-mentions/plans/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertJson(['message' => 'Plan not found.']);
    }

    public function test_staff_cannot_delete_a_plan(): void
    {
        $plan = $this->makePlan();

        $this->deleteAs($this->makeUserWithRole('staff'), "/api/admin/premium-mentions/plans/{$plan->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('premium_mentions_plans', ['id' => $plan->id]);
    }

    public function test_guest_cannot_delete_a_plan(): void
    {
        $plan = $this->makePlan();

        $this->deleteJson("/api/admin/premium-mentions/plans/{$plan->id}")->assertUnauthorized();

        $this->assertDatabaseHas('premium_mentions_plans', ['id' => $plan->id]);
    }
}
