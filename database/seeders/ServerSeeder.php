<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $root_team = Team::find(1);
        Server::create([
            'id' => 1,
            'name' => "testing-host",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host",
            'team_id' => $root_team->id,
        ]);
        Server::create([
            'id' => 2,
            'name' => "testing-host2",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host-2",
            'team_id' => $root_team->id,
        ]);
    }
}
