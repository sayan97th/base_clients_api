<?php

namespace Tests\Feature\Admin\Team;

use App\Models\AdminTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Admin\InteractsWithAdminRoles;
use Tests\TestCase;

/**
 * Deleting admin teams and removing team members.
 *
 * DELETE /api/admin/teams/{id}
 * DELETE /api/admin/teams/{id}/members/{user_id}
 */
class AdminTeamDeleteTest extends TestCase
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

    private function makeTeam(array $overrides = []): AdminTeam
    {
        return AdminTeam::create(array_merge([
            'name'        => 'Marketing',
            'description' => 'Outreach and content team.',
            'color'       => '#3B82F6',
            'is_active'   => true,
            'created_by'  => $this->admin->id,
        ], $overrides));
    }

    private function addMember(AdminTeam $team, User $user): void
    {
        $team->members()->attach($user->id, ['role' => 'member']);
    }

    // ── Delete team ───────────────────────────────────────────────────────────

    public function test_admin_can_delete_a_team(): void
    {
        $team = $this->makeTeam();

        $this->deleteAs($this->admin, "/api/admin/teams/{$team->id}")
            ->assertOk()
            ->assertJson(['message' => 'Team deleted successfully.']);

        $this->assertDatabaseMissing('admin_teams', ['id' => $team->id]);
    }

    public function test_deleting_a_team_removes_its_memberships_but_keeps_the_users(): void
    {
        $team = $this->makeTeam();
        $member = $this->makeUserWithRole('staff');
        $this->addMember($team, $member);

        $this->deleteAs($this->admin, "/api/admin/teams/{$team->id}")->assertOk();

        $this->assertDatabaseMissing('admin_team_user', ['admin_team_id' => $team->id]);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_deleting_a_missing_team_returns_not_found(): void
    {
        $this->deleteAs($this->admin, '/api/admin/teams/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertJson(['message' => 'Team not found.']);
    }

    public function test_client_cannot_delete_a_team(): void
    {
        $team = $this->makeTeam();

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/teams/{$team->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('admin_teams', ['id' => $team->id]);
    }

    public function test_guest_cannot_delete_a_team(): void
    {
        $team = $this->makeTeam();

        $this->deleteJson("/api/admin/teams/{$team->id}")->assertUnauthorized();

        $this->assertDatabaseHas('admin_teams', ['id' => $team->id]);
    }

    // ── Remove member ─────────────────────────────────────────────────────────

    public function test_admin_can_remove_a_member_from_a_team(): void
    {
        $team = $this->makeTeam();
        $removed = $this->makeUserWithRole('staff');
        $kept = $this->makeUserWithRole('staff');
        $this->addMember($team, $removed);
        $this->addMember($team, $kept);

        $this->deleteAs($this->admin, "/api/admin/teams/{$team->id}/members/{$removed->id}")
            ->assertOk()
            ->assertJson(['message' => 'Member removed successfully.']);

        $this->assertDatabaseMissing('admin_team_user', [
            'admin_team_id' => $team->id,
            'user_id'       => $removed->id,
        ]);
        $this->assertDatabaseHas('admin_team_user', [
            'admin_team_id' => $team->id,
            'user_id'       => $kept->id,
        ]);
        $this->assertDatabaseHas('admin_teams', ['id' => $team->id]);
    }

    public function test_removing_a_user_who_is_not_a_member_returns_not_found(): void
    {
        $team = $this->makeTeam();
        $outsider = $this->makeUserWithRole('staff');

        $this->deleteAs($this->admin, "/api/admin/teams/{$team->id}/members/{$outsider->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'User is not a member of this team.']);
    }

    public function test_removing_a_member_from_a_missing_team_returns_not_found(): void
    {
        $member = $this->makeUserWithRole('staff');

        $this->deleteAs($this->admin, "/api/admin/teams/00000000-0000-0000-0000-000000000000/members/{$member->id}")
            ->assertNotFound()
            ->assertJson(['message' => 'Team not found.']);
    }

    public function test_client_cannot_remove_a_member(): void
    {
        $team = $this->makeTeam();
        $member = $this->makeUserWithRole('staff');
        $this->addMember($team, $member);

        $this->deleteAs($this->makeUserWithRole('client'), "/api/admin/teams/{$team->id}/members/{$member->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('admin_team_user', [
            'admin_team_id' => $team->id,
            'user_id'       => $member->id,
        ]);
    }
}
