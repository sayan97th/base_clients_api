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
            ['name' => 'super_admin', 'display_name' => 'Super Administrator', 'description' => 'Full system access'],
            ['name' => 'owner', 'display_name' => 'Owner', 'description' => 'Organization owner'],
            ['name' => 'admin', 'display_name' => 'Administrator', 'description' => 'Organization administrator'],
            ['name' => 'user', 'display_name' => 'User', 'description' => 'Standard user'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        $permissions = [
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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission['name']], $permission);
        }

        $superAdmin = Role::where('name', 'super_admin')->first();
        $superAdmin->permissions()->sync(Permission::pluck('id'));

        $owner = Role::where('name', 'owner')->first();
        $owner->permissions()->sync(
            Permission::whereIn('name', [
                'users.view', 'users.create', 'users.update', 'users.delete',
                'roles.view', 'roles.assign',
                'organizations.view', 'organizations.update',
                'clients.view', 'clients.create', 'clients.update', 'clients.delete',
            ])->pluck('id')
        );

        $admin = Role::where('name', 'admin')->first();
        $admin->permissions()->sync(
            Permission::whereIn('name', [
                'users.view', 'users.create', 'users.update',
                'organizations.view',
                'clients.view', 'clients.create', 'clients.update',
            ])->pluck('id')
        );

        $user = Role::where('name', 'user')->first();
        $user->permissions()->sync(
            Permission::whereIn('name', [
                'users.view',
                'organizations.view',
                'clients.view',
            ])->pluck('id')
        );
    }
}
