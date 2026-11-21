<?php

namespace Tests\Support\Admin;

use App\Models\Role;
use App\Models\User;

/**
 * Shared setup for admin endpoint tests: seeds the roles used by the
 * `role:` middleware and creates active users that hold one of them.
 */
trait InteractsWithAdminRoles
{
    protected function seedAdminRoles(): void
    {
        $roles = [
            'super_admin' => 'Super Admin',
            'admin'       => 'Admin',
            'staff'       => 'Staff',
            'client'      => 'Client',
        ];

        foreach ($roles as $name => $display_name) {
            Role::firstOrCreate(['name' => $name], [
                'display_name' => $display_name,
                'description'  => $display_name,
            ]);
        }
    }

    protected function makeUserWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $user->assignRole($role);

        return $user;
    }

    /** Sends an authenticated DELETE request as the given user. */
    protected function deleteAs(User $user, string $uri)
    {
        return $this->actingAs($user, 'api')->deleteJson($uri);
    }
}
