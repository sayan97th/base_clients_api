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
        $this->call(ResourcePermissionsSeeder::class);
        $this->call(DrTierSeeder::class);
        $this->call(ContentRefreshTierSeeder::class);
        $this->call(SmeAuthoredTierSeeder::class);
        $this->call(SmeCollaborationTierSeeder::class);
        $this->call(SmeEnhancedTierSeeder::class);
        $this->call(CouponSeeder::class);
        $this->call(PremiumMentionsPlanSeeder::class);
        $this->call(SeoPackagesSeeder::class);
        $this->call(NewsPostSeeder::class);

        $this->seedOrganization();

        $this->call(ResourceSeeder::class);
        $this->call(StaffUserSeeder::class);
        $this->call(BacklinkOrderSeeder::class);
        $this->call(NewsPlacementSeeder::class);
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
