<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

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
            'company' => 'Test Company',
        ]);

        $user->assignRole('super_admin');
    }
}
