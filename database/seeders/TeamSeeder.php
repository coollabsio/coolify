<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $normal_user_in_root_team = User::find(1);
        $root_user_personal_team = Team::find(0);
        $root_user_personal_team->description = 'The root team';
        $root_user_personal_team->save();

        $normal_user_in_root_team->teams()->attach($root_user_personal_team);
        $normal_user_not_in_root_team = User::find(2);
        $normal_user_in_root_team_personal_team = Team::find(1);
        $normal_user_not_in_root_team->teams()->attach($normal_user_in_root_team_personal_team, ['role' => 'admin']);
    }
}
