<?php

namespace Tests\Feature\Notification;

use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the interstitial gate an admin/staff account lands on when opening a
 * client-side notification email link (e.g. "View Invoice") while signed in on the
 * admin side. See NotificationRedirectController for the full rationale.
 */
class NotificationRedirectControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $super_admin;
    private User $admin;
    private User $staff;
    private User $client;
    private NotificationService $service;

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
        // Mirrors the production seeder default: the "admin" role is granted
        // impersonation by default. Individual tests revoke it to exercise the
        // insufficient-permission path.
        $admin_role->permissions()->syncWithoutDetaching([$impersonate_permission->id]);

        $this->super_admin = User::factory()->create(['is_active' => true]);
        $this->super_admin->assignRole('super_admin');

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->staff = User::factory()->create(['is_active' => true]);
        $this->staff->assignRole('staff');

        $this->client = User::factory()->create(['is_active' => true]);
        $this->client->assignRole('client');

        $this->service = app(NotificationService::class);
    }

    private function revokeImpersonatePermissionFromAdminRole(): void
    {
        $admin_role = Role::where('name', 'admin')->first();
        $permission = Permission::where('name', 'users.impersonate')->first();
        $admin_role->permissions()->detach($permission->id);
        $this->admin->refresh();
        $this->admin->load('roles.permissions');
    }

    private function createClientInvoiceNotification(): Notification
    {
        return $this->service->createNotification(
            $this->client,
            'invoice',
            'Your invoice has been created.',
            [
                'link'      => '/invoices/abc-123',
                'mail_data' => ['skip_email' => true],
            ]
        );
    }

    public function test_context_reports_the_notification_belongs_to_a_client(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $response = $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertOk();

        $response->assertJsonPath('data.belongs_to_client', true)
            ->assertJsonPath('data.redirect_path', '/invoices/abc-123')
            ->assertJsonPath('data.can_impersonate', true)
            ->assertJsonPath('data.target_user.id', $this->client->id);
    }

    public function test_context_reports_cannot_impersonate_when_admin_lacks_the_permission(): void
    {
        $this->revokeImpersonatePermissionFromAdminRole();
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertOk()
            ->assertJsonPath('data.belongs_to_client', true)
            ->assertJsonPath('data.can_impersonate', false);
    }

    public function test_context_reports_cannot_impersonate_for_staff_role(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->staff, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertOk()
            ->assertJsonPath('data.belongs_to_client', true)
            ->assertJsonPath('data.can_impersonate', false);
    }

    public function test_context_rejects_a_notification_that_belongs_to_an_admin(): void
    {
        $notification = $this->service->createNotification(
            $this->staff,
            'ticket',
            'A ticket was assigned to you.',
            ['link' => '/admin/support-tickets/1', 'mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertStatus(422);
    }

    public function test_context_never_returns_an_admin_prefixed_redirect_path(): void
    {
        $notification = $this->service->createNotification(
            $this->client,
            'invoice',
            'Your invoice has been created.',
            ['link' => '/admin/invoices/999', 'mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->admin, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertOk()
            ->assertJsonPath('data.redirect_path', '/');
    }

    public function test_impersonate_succeeds_for_an_admin_with_the_permission(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $response = $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertOk();

        $response->assertJsonPath('impersonated_user.id', $this->client->id)
            ->assertJsonStructure(['impersonation_token', 'token_type', 'expires_in', 'impersonated_user', 'admin_user']);
    }

    public function test_impersonate_is_rejected_when_the_admin_lacks_the_permission(): void
    {
        $this->revokeImpersonatePermissionFromAdminRole();
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'You have insufficient permissions to use the impersonation feature.']);
    }

    public function test_impersonate_is_rejected_for_staff_role(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->staff, 'api')
            ->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertStatus(403);
    }

    /**
     * The single most important guarantee of this endpoint: even a super_admin,
     * who bypasses hasPermission() entirely, must never be able to use THIS action
     * to impersonate another staff-side account. It exists only for client
     * notifications.
     */
    public function test_impersonate_refuses_to_impersonate_a_staff_account_even_for_super_admin(): void
    {
        $staff_notification = $this->service->createNotification(
            $this->staff,
            'system',
            'A system notification.',
            ['link' => '/admin/dashboard', 'mail_data' => ['skip_email' => true]]
        );

        $this->actingAs($this->super_admin, 'api')
            ->postJson("/api/admin/notifications/{$staff_notification->id}/impersonate")
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'This action can only be used to impersonate client accounts.']);
    }

    public function test_impersonate_is_rejected_when_the_target_client_account_is_disabled(): void
    {
        $this->client->update(['is_active' => false]);
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->admin, 'api')
            ->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $this->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertUnauthorized();

        $this->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertUnauthorized();
    }

    public function test_client_role_cannot_use_either_endpoint(): void
    {
        $notification = $this->createClientInvoiceNotification();

        $this->actingAs($this->client, 'api')
            ->getJson("/api/admin/notifications/{$notification->id}/redirect-context")
            ->assertForbidden();

        $this->actingAs($this->client, 'api')
            ->postJson("/api/admin/notifications/{$notification->id}/impersonate")
            ->assertForbidden();
    }
}
