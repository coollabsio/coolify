<?php

namespace Database\Seeders;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    private const LIMA_SENTINEL_URL = 'http://host.lima.internal:8000';

    private const LIMA_SERVERS = [
        ['uuid' => 'lima-ubuntu-2404', 'name' => 'lima-ubuntu-2404', 'port' => 2222],
        ['uuid' => 'lima-ubuntu-2604', 'name' => 'lima-ubuntu-2604', 'port' => 2223],
    ];

    public function run(): void
    {
        Server::create([
            'id' => 0,
            'uuid' => 'localhost',
            'name' => 'localhost',
            'description' => 'This is a test docker container in development mode',
            'ip' => 'coolify-testing-host',
            'team_id' => 0,
            'private_key_id' => 1,
            'proxy' => [
                'type' => ProxyTypes::TRAEFIK->value,
                'status' => ProxyStatus::EXITED->value,
            ],
        ]);

        foreach (self::LIMA_SERVERS as $limaServer) {
            $server = Server::create([
                'uuid' => $limaServer['uuid'],
                'name' => $limaServer['name'],
                'description' => 'This is a Lima VM for local development testing',
                'ip' => 'host.docker.internal',
                'port' => $limaServer['port'],
                'team_id' => 0,
                'private_key_id' => 1,
                'proxy' => [
                    'type' => ProxyTypes::TRAEFIK->value,
                    'status' => ProxyStatus::EXITED->value,
                ],
            ]);

            $server->settings->forceFill([
                'sentinel_custom_url' => self::LIMA_SENTINEL_URL,
            ])->saveQuietly();
        }
    }
}
