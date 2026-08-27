<?php

namespace Tests\Feature\Impersonation;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $super_admin;
    private User $admin;
    private User $staff;
    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Administrator', 'description' => 'Full system access']);
        $admin_role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin', 'description' => 'Admin']);
        Role::firstOrCreate(['name' => 'staff'], ['display_name' => 'Staff', 'description' => 'Staff']);
        Role::firstOrCreate(['name' => 'client'], ['display_name' => 'Client', 'description' => 'Client user']);

        $impersonate_permission = Permission::firstOrCreate(
            ['name' => 'users.impersonate'],
            ['display_name' => 'Impersonate Users']
        );
        $admin_role->permissions()->syncWithoutDetaching([$impersonate_permission->id]);

        $this->super_admin = User::factory()->create(['is_active' => true]);
        $this->super_admin->assignRole('super_admin');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('staff');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');
    }

    public function test_admin_with_permission_can_impersonate_a_client(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/users/{$this->client->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('impersonated_user.id', $this->client->id);
    }

    public function test_admin_without_the_permission_is_rejected_even_though_the_role_check_passes(): void
    {
        $admin_role = Role::where('name', 'admin')->first();
        $admin_role->permissions()->detach(Permission::where('name', 'users.impersonate')->first()->id);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/users/{$this->client->id}/impersonate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'You have insufficient permissions to use the impersonation feature.']);
    }

    public function test_super_admin_bypasses_the_permission_check(): void
    {
        // super_admin never has an explicit users.impersonate grant, hasPermission()
        // short-circuits to true for the role itself (see HasRoles::hasPermission()).
        $this->actingAs($this->super_admin, 'api')
            ->postJson("/api/admin/users/{$this->client->id}/impersonate")
            ->assertOk();
    }

    public function test_staff_role_cannot_reach_the_endpoint_at_all(): void
    {
        $this->actingAs($this->staff, 'api')
            ->postJson("/api/admin/users/{$this->client->id}/impersonate")
            ->assertForbidden();
    }

    public function test_client_role_cannot_reach_the_endpoint_at_all(): void
    {
        $this->actingAs($this->client, 'api')
            ->postJson("/api/admin/users/{$this->admin->id}/impersonate")
            ->assertForbidden();
    }

    public function test_admin_cannot_impersonate_another_staff_account(): void
    {
        $other_admin = User::factory()->create(['is_active' => true]);
        $other_admin->assignRole('admin');

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/users/{$other_admin->id}/impersonate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Only super admins can impersonate admin-side users.']);
    }

    public function test_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $other_super_admin = User::factory()->create(['is_active' => true]);
        $other_super_admin->assignRole('super_admin');

        $this->actingAs($this->super_admin, 'api')
            ->postJson("/api/admin/users/{$other_super_admin->id}/impersonate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Super admin accounts cannot be impersonated.']);
    }

    public function test_cannot_impersonate_a_disabled_account(): void
    {
        $this->client->update(['is_active' => false]);

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/users/{$this->client->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_cannot_impersonate_self(): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/users/{$this->admin->id}/impersonate")
            ->assertStatus(422);
    }
}
