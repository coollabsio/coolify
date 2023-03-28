<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Seeder;

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
            'name' => "testing-local-docker-container",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
        ]);
        Server::create([
            'id' => 2,
            'name' => "testing-local-docker-container-2",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host-2",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
        ]);
        Server::create([
            'id' => 3,
            'name' => "localhost",
            'description' => "This is the local machine",
            'user' => 'ab',
            'ip' => "172.17.0.1",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
        ]);
    }
}
