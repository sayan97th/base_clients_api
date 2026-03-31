<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ResourcePermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── Create all resource permissions ──────────────────────────────────

        $permissions = [
            ['name' => 'resources.view',         'display_name' => 'View Resources'],
            ['name' => 'resources.show',         'display_name' => 'View Resource Detail'],
            ['name' => 'resources.create',       'display_name' => 'Create Resources'],
            ['name' => 'resources.edit',         'display_name' => 'Edit Resources'],
            ['name' => 'resources.publish',      'display_name' => 'Publish/Unpublish Resources'],
            ['name' => 'resources.delete',       'display_name' => 'Delete Resources'],
            ['name' => 'resources.manage_files', 'display_name' => 'Manage Resource Files'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        // ── Assign permissions to roles ───────────────────────────────────────

        $super_admin = Role::firstOrCreate(['name' => 'super_admin'], [
            'display_name' => 'Super Administrator',
            'description'  => 'Full system access',
        ]);

        $admin = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'description'  => 'Manages the platform, can invite staff',
        ]);

        $staff = Role::firstOrCreate(['name' => 'staff'], [
            'display_name' => 'Staff',
            'description'  => 'Operational team member',
        ]);

        // super_admin — all resource permissions (plus any existing ones)
        $super_admin->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', [
                'resources.view',
                'resources.show',
                'resources.create',
                'resources.edit',
                'resources.publish',
                'resources.delete',
                'resources.manage_files',
            ])->pluck('id')
        );

        // admin — all resource permissions
        $admin->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', [
                'resources.view',
                'resources.show',
                'resources.create',
                'resources.edit',
                'resources.publish',
                'resources.delete',
                'resources.manage_files',
            ])->pluck('id')
        );

        // staff — cannot delete resources
        $staff->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', [
                'resources.view',
                'resources.show',
                'resources.create',
                'resources.edit',
                'resources.publish',
                'resources.manage_files',
            ])->pluck('id')
        );
    }
}
