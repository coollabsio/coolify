<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestTeamSeeder extends Seeder
{
    public function run(): void
    {
        // User has 2 teams, 1 personal, 1 other where it is the owner and no other members are in the team
        $user = User::factory()->create([
            'name' => '1 personal, 1 other team, owner, no other members',
            'email' => '1@example.com',
        ]);
        $team = Team::create([
            'name' => '1@example.com',
            'personal_team' => false,
            'show_boarding' => true,
        ]);
        $user->teams()->attach($team, ['role' => 'owner']);

        // User has 2 teams, 1 personal, 1 other where it is the owner and 1 other member is in the team
        $user = User::factory()->create([
            'name' => 'owner: 1 personal, 1 other team, owner, 1 other member',
            'email' => '2@example.com',
        ]);
        $team = Team::create([
            'name' => '2@example.com',
            'personal_team' => false,
            'show_boarding' => true,
        ]);
        $user->teams()->attach($team, ['role' => 'owner']);
        $user = User::factory()->create([
            'name' => 'member: 1 personal, 1 other team, owner, 1 other member',
            'email' => '3@example.com',
        ]);
        $team->members()->attach($user, ['role' => 'member']);
    }
}
