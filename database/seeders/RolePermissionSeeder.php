<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $roles = [
            // Legacy roles (kept for backward compatibility)
            ['name' => 'super_admin', 'display_name' => 'Super Administrator', 'description' => 'Full system access'],
            ['name' => 'owner', 'display_name' => 'Owner', 'description' => 'Organization owner'],
            ['name' => 'user', 'display_name' => 'User', 'description' => 'Standard user'],
            // Portal roles used by the frontend for routing decisions
            ['name' => 'admin', 'display_name' => 'Admin', 'description' => 'Manages the platform, can invite staff'],
            ['name' => 'staff', 'display_name' => 'Staff', 'description' => 'Operational team member'],
            ['name' => 'client', 'display_name' => 'Client', 'description' => 'Regular paying user'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        $permissions = [
            // Legacy permissions
            ['name' => 'users.view', 'display_name' => 'View Users'],
            ['name' => 'users.create', 'display_name' => 'Create Users'],
            ['name' => 'users.update', 'display_name' => 'Update Users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users'],
            ['name' => 'roles.view', 'display_name' => 'View Roles'],
            ['name' => 'roles.assign', 'display_name' => 'Assign Roles'],
            ['name' => 'organizations.view', 'display_name' => 'View Organizations'],
            ['name' => 'organizations.create', 'display_name' => 'Create Organizations'],
            ['name' => 'organizations.update', 'display_name' => 'Update Organizations'],
            ['name' => 'organizations.delete', 'display_name' => 'Delete Organizations'],
            ['name' => 'clients.view', 'display_name' => 'View Clients'],
            ['name' => 'clients.create', 'display_name' => 'Create Clients'],
            ['name' => 'clients.update', 'display_name' => 'Update Clients'],
            ['name' => 'clients.delete', 'display_name' => 'Delete Clients'],
            ['name' => 'teams.view', 'display_name' => 'View Teams'],
            ['name' => 'teams.create', 'display_name' => 'Create Teams'],
            ['name' => 'teams.update', 'display_name' => 'Update Teams'],
            ['name' => 'teams.delete', 'display_name' => 'Delete Teams'],
            ['name' => 'teams.invite', 'display_name' => 'Invite Team Members'],
            // Portal permissions used by frontend sidebar visibility
            ['name' => 'orders.view', 'display_name' => 'View Orders'],
            ['name' => 'invoices.view', 'display_name' => 'View Invoices'],
            ['name' => 'invitations.manage', 'display_name' => 'Manage Invitations'],
            // Service management — restricted to super_admin and admin
            ['name' => 'services.manage', 'display_name' => 'Manage Services'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }

        // Legacy role permissions
        $superAdmin = Role::where('name', 'super_admin')->first();
        $superAdmin->permissions()->sync(Permission::pluck('id'));

        $owner = Role::where('name', 'owner')->first();
        $owner->permissions()->sync(
            Permission::whereIn('name', [
                'users.view', 'users.create', 'users.update', 'users.delete',
                'roles.view', 'roles.assign',
                'organizations.view', 'organizations.update',
                'clients.view', 'clients.create', 'clients.update', 'clients.delete',
                'teams.view', 'teams.create', 'teams.update', 'teams.delete', 'teams.invite',
            ])->pluck('id')
        );

        $user = Role::where('name', 'user')->first();
        $user->permissions()->sync(
            Permission::whereIn('name', [
                'users.view',
                'organizations.view',
                'clients.view',
                'teams.view',
            ])->pluck('id')
        );

        // super_admin already has all permissions (synced above)

        $admin = Role::where('name', 'admin')->first();
        $admin->permissions()->sync(
            Permission::whereIn('name', [
                'users.view',
                'organizations.view',
                'orders.view',
                'invoices.view',
                'invitations.manage',
                'services.manage',
                // Legacy permissions kept for backward compat
                'users.create', 'users.update',
                'clients.view', 'clients.create', 'clients.update',
                'teams.view', 'teams.create', 'teams.update', 'teams.invite',
            ])->pluck('id')
        );

        $staff = Role::where('name', 'staff')->first();
        $staff->permissions()->sync(
            Permission::whereIn('name', [
                'users.view',
                'organizations.view',
                'orders.view',
                'invoices.view',
            ])->pluck('id')
        );

        $client = Role::where('name', 'client')->first();
        $client->permissions()->sync([]);
    }
}
