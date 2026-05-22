<?php

namespace Database\Seeders\Dev;

use App\Models\AdminTeam;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminTeamSeeder extends Seeder
{
    /**
     * Seed the pre-defined admin link-building teams.
     * Uses updateOrCreate so re-running the seeder is safe.
     */
    public function run(): void
    {
        $creator = User::where('email', 'admin@97thfloor.com')->first();
        $creator_id = $creator?->id ?? 1;

        $teams = [
            ['name' => 'John Team',   'color' => '#3B82F6', 'max_capacity' => 50], // blue
            ['name' => 'Kenny Team',  'color' => '#10B981', 'max_capacity' => 50], // emerald
            ['name' => 'Sarah Team',  'color' => '#F59E0B', 'max_capacity' => 50], // amber
            ['name' => 'Mike Team',   'color' => '#EF4444', 'max_capacity' => 50], // red
            ['name' => 'Alex Team',   'color' => '#8B5CF6', 'max_capacity' => 50], // violet
            ['name' => 'Chris Team',  'color' => '#F97316', 'max_capacity' => 50], // orange
            ['name' => 'Emily Team',  'color' => '#06B6D4', 'max_capacity' => 50], // cyan
        ];

        foreach ($teams as $team_data) {
            AdminTeam::updateOrCreate(
                ['name' => $team_data['name']],
                [
                    'description'  => null,
                    'color'        => $team_data['color'],
                    'max_capacity' => $team_data['max_capacity'],
                    'is_active'    => true,
                    'created_by'   => $creator_id,
                ]
            );
        }
    }
}
