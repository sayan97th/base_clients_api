<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $this->seedOrganization();
    }

    private function seedOrganization(): void
    {
        $organization = Organization::updateOrCreate(
            ['slug' => 'base-search-marketing'],
            [
                'name' => 'BASE Search Marketing',
                'description' => 'BASE Search Marketing organization',
                'timezone' => 'America/Boise',
            ]
        );

        $this->seedAdminUser($organization);
    }

    private function seedAdminUser(Organization $organization): void
    {
        $admin_user = User::updateOrCreate(
            ['email' => 'admin@97thfloor.com'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Admin',
                'business_email' => 'admin@97thfloor.com',
                'password' => 'admin',
                'organization_id' => $organization->id,
            ]
        );

        $admin_user->preference()->updateOrCreate(
            ['user_id' => $admin_user->id],
            [
                'timezone' => 'America/Boise',
                'language' => 'en',
            ]
        );

        $admin_user->billingAddress()->updateOrCreate(
            ['user_id' => $admin_user->id],
            ['company' => 'BASE Search Marketing']
        );

        $admin_user->syncRoles(['super_admin', 'owner']);
    }
}
