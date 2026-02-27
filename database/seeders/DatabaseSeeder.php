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
        $user = User::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'business_email' => 'test@company.com',
        ]);

        $user->preference()->create([
            'timezone' => 'America/New_York',
            'language' => 'en',
        ]);

        $user->billingAddress()->create([
            'company' => 'Test Company',
        ]);
    }
}
