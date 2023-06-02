<?php

namespace Database\Seeders;

use App\Data\ServerMetadata;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
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
        $root_team = Team::find(0);
        $private_key_1 = PrivateKey::find(1);

        Server::create([
            'name' => "testing-local-docker-container",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id,
            'extra_attributes' => ServerMetadata::from([
                'proxy_type' => ProxyTypes::TRAEFIK_V2->value,
                'proxy_status' => ProxyStatus::EXITED->value
            ]),
        ]);
        Server::create([
            'name' => "testing-local-docker-container-2",
            'description' => "This is a test docker container",
            'ip' => "coolify-testing-host-2",
            'team_id' => $root_team->id,
            'private_key_id' => $private_key_1->id
        ]);
    }
}
