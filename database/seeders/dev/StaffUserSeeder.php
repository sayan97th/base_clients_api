<?php

namespace Database\Seeders\Dev;

use App\Models\User;
use Illuminate\Database\Seeder;

class StaffUserSeeder extends Seeder
{
    /**
     * Seeds the four staff users referenced as link builders
     * in the backlink orders seed data.
     */
    public function run(): void
    {
        $staff_members = [
            [
                'first_name'     => 'Kaitlin',
                'last_name'      => 'Anderson',
                'email'          => 'kaitlin.anderson@97thfloor.com',
                'password'       => 'password',
                'staff_capacity' => 23,
            ],
            [
                'first_name'     => 'Amanda',
                'last_name'      => 'Hevener',
                'email'          => 'amanda.hevener@97thfloor.com',
                'password'       => 'password',
                'staff_capacity' => 23,
            ],
            [
                'first_name'     => 'Lauren',
                'last_name'      => 'Barney',
                'email'          => 'lauren.barney@97thfloor.com',
                'password'       => 'password',
                'staff_capacity' => 14,
            ],
            [
                'first_name'     => 'Krista',
                'last_name'      => 'Bennett',
                'email'          => 'krista.bennett@97thfloor.com',
                'password'       => 'password',
                'staff_capacity' => 14,
            ],
        ];

        foreach ($staff_members as $member) {
            $user = User::updateOrCreate(
                ['email' => $member['email']],
                [
                    'first_name'     => $member['first_name'],
                    'last_name'      => $member['last_name'],
                    'password'       => $member['password'],
                    'staff_capacity' => $member['staff_capacity'],
                    'is_active'      => true,
                ]
            );

            $user->syncRoles(['staff']);
        }
    }
}
