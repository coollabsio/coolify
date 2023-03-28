<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $root_team = Team::find(1);
        $private_key_1 = PrivateKey::find(1);
        Server::create([
            'id' => 1,
            'name' => "testing-host",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
        ]);
        Server::create([
            'id' => 2,
            'name' => "testing-host2",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host-2",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
        ]);

    }
}
