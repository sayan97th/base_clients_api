<?php

namespace Tests\Feature\Admin\Dashboard;

use App\Models\LinkBuildingOrderPlacement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'description' => 'Super admin']);
        Role::firstOrCreate(['name' => 'admin'],       ['display_name' => 'Admin',       'description' => 'Admin user']);
        Role::firstOrCreate(['name' => 'staff'],       ['display_name' => 'Staff',       'description' => 'Staff user']);

        $this->admin = $this->userWithRole('super_admin', 'Tester', 'QA');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function userWithRole(string $role, string $first_name, string $last_name): User
    {
        $user = User::factory()->create([
            'is_active'  => true,
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function placement(array $overrides = []): LinkBuildingOrderPlacement
    {
        return LinkBuildingOrderPlacement::create(array_merge([
            'order_id'     => 'BL-' . uniqid(),
            'client'       => 'Default Client',
            'keyword'      => 'default keyword',
            'landing_page' => 'https://example.com',
            'link_type'    => 'DR 30+ External',
            'status'       => 'New Request',
        ], $overrides));
    }

    private function teamHealth(): array
    {
        return $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/dashboard/team-health')
            ->assertOk()
            ->json('data');
    }

    // ─── Team member names ────────────────────────────────────────────────────

    public function test_link_builder_name_is_shown_as_first_and_last_name(): void
    {
        $this->placement(['link_builder' => '1. Bringhurst, Krista']);

        $data = $this->teamHealth();

        $this->assertCount(1, $data);
        $this->assertSame('Krista Bringhurst', $data[0]['name']);
        $this->assertSame('krista-bringhurst', $data[0]['team_id']);
    }

    public function test_test_accounts_are_not_listed_as_team_members(): void
    {
        $this->placement(['assigned_admin_user_id' => $this->admin->id]);

        $this->assertSame([], $this->teamHealth());
    }

    public function test_placement_assigned_to_test_account_falls_back_to_link_builder(): void
    {
        $this->placement([
            'assigned_admin_user_id' => $this->admin->id,
            'link_builder'           => '1. Bringhurst, Krista',
        ]);

        $data = $this->teamHealth();

        $this->assertSame(['Krista Bringhurst'], array_column($data, 'name'));
    }

    public function test_assigned_team_member_takes_priority_over_link_builder(): void
    {
        $kaitlin = $this->userWithRole('staff', 'Kaitlin', 'Anderson');

        $this->placement([
            'assigned_admin_user_id' => $kaitlin->id,
            'link_builder'           => '1. Bringhurst, Krista',
        ]);

        $this->assertSame(['Kaitlin Anderson'], array_column($this->teamHealth(), 'name'));
    }

    public function test_assigned_user_and_link_builder_rows_merge_into_one_member(): void
    {
        $kaitlin = $this->userWithRole('staff', 'Kaitlin', 'Anderson');

        $this->placement(['assigned_admin_user_id' => $kaitlin->id]);
        $this->placement(['link_builder' => '2. Anderson, Kaitlin']);

        $data = $this->teamHealth();

        $this->assertCount(1, $data);
        $this->assertSame(2, $data[0]['total_links']);
    }

    public function test_members_are_sorted_by_name(): void
    {
        $this->placement(['link_builder' => '3. Acosta, Celeste']);
        $this->placement(['link_builder' => '2. Anderson, Kaitlin']);
        $this->placement(['link_builder' => '1. Barney, Lauren']);

        $this->assertSame(
            ['Celeste Acosta', 'Kaitlin Anderson', 'Lauren Barney'],
            array_column($this->teamHealth(), 'name')
        );
    }

    // ─── Health metrics ───────────────────────────────────────────────────────

    public function test_closed_placements_are_excluded(): void
    {
        $this->placement(['link_builder' => '2. Anderson, Kaitlin', 'status' => 'Live']);
        $this->placement(['link_builder' => '2. Anderson, Kaitlin', 'status' => 'Cancelled']);

        $this->assertSame([], $this->teamHealth());
    }

    public function test_health_counts_delayed_placements(): void
    {
        $this->placement([
            'link_builder'            => '2. Anderson, Kaitlin',
            'estimated_delivery_date' => now()->subDays(3)->format('m/d/Y'),
        ]);
        $this->placement([
            'link_builder'            => '2. Anderson, Kaitlin',
            'estimated_delivery_date' => now()->addDays(3)->format('m/d/Y'),
        ]);

        $member = $this->teamHealth()[0];

        $this->assertSame(2, $member['total_links']);
        $this->assertSame(1, $member['links_delayed']);
        $this->assertSame(1, $member['links_on_track']);
        $this->assertSame(50, $member['health_pct']);
    }

    // ─── Capacity ─────────────────────────────────────────────────────────────

    public function test_capacity_uses_the_same_team_members(): void
    {
        $this->placement(['assigned_admin_user_id' => $this->admin->id]);
        $this->placement(['link_builder' => '1. Bringhurst, Krista']);

        $data = $this->actingAs($this->admin, 'api')
            ->getJson('/api/admin/dashboard/team-capacity')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Krista Bringhurst', $data[0]['name']);
        $this->assertSame(1, $data[0]['total_assigned']);
        $this->assertSame(50, $data[0]['max_capacity']);
    }
}
