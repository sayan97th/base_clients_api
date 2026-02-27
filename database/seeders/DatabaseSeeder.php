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

        $organization = Organization::create([
            'name' => 'BASE Search Marketing',
            'slug' => 'base-search-marketing',
            'description' => 'BASE Search Marketing organization',
            'timezone' => 'America/Boise',
        ]);

        $user = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'Admin',
            'email' => 'admin@97thfloor.com',
            'business_email' => 'admin@97thfloor.co',
            'password' => 'admin',
        ]);

        $user->preference()->create([
            'timezone' => 'America/Boise',
            'language' => 'en',
        ]);

        $user->billingAddress()->create([
            'company' => 'BASE Search Marketing',
        ]);

        $user->assignRole('super_admin');
        $user->assignRole('owner', $organization->id);
    }
}
