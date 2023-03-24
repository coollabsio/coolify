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
        $root_user = User::find(1);
        $normal_user = User::find(2);

        $root_user_personal_team = Team::create([
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
        DB::table('team_user')->insert([
            'team_id' =>  $root_user_personal_team->id,
            'user_id' => $root_user->id,
            'role' => 'admin',
        ]);
        DB::table('team_user')->insert([
            'team_id' =>  $root_user_other_team->id,
            'user_id' => $root_user->id,
            'role' => 'admin',
        ]);
        DB::table('team_user')->insert([
            'team_id' =>  $normal_user_personal_team->id,
            'user_id' => $normal_user->id,
            'role' => 'admin',
        ]);
    }
}
