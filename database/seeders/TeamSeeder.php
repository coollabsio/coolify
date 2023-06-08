<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $root_user = User::find(0);
        $normal_user = User::find(1);

        $root_user_personal_team = Team::create([
            'id' => 0,
            'name' => "Root Team",
            'personal_team' => true,
        ]);
        $root_user_other_team = Team::create([
            'name' => "Root User's Other Team",
            'personal_team' => false,
        ]);
        $normal_user_personal_team = Team::create([
            'name' => 'Normal Team',
            'personal_team' => true,
        ]);
        // $root_user->teams()->attach($root_user_personal_team);
        // $root_user->teams()->attach($root_user_other_team);
        // $normal_user->teams()->attach($normal_user_personal_team);
        // $normal_user->teams()->attach($root_user_personal_team);
        DB::table('team_user')->insert([
            'team_id' => $root_user_personal_team->id,
            'user_id' => $root_user->id,
            'role' => 'admin',
        ]);
        DB::table('team_user')->insert([
            'team_id' =>  $root_user_other_team->id,
            'user_id' => $root_user->id,
        ]);
        DB::table('team_user')->insert([
            'team_id' =>  $normal_user_personal_team->id,
            'user_id' => $normal_user->id,
        ]);
        DB::table('team_user')->insert([
            'team_id' =>  $root_user_personal_team->id,
            'user_id' => $normal_user->id,
        ]);
    }
}
