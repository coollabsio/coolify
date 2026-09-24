<?php

namespace Database\Seeders;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    public function run(): void
    {
        $server = Server::withTrashed()->find(0) ?? new Server;
        $attributes = [
            'id' => 0,
            'uuid' => 'localhost',
            'name' => 'localhost',
            'description' => 'This is a test docker container in development mode',
            'ip' => 'coolify-testing-host',
            'port' => 22,
            'user' => 'root',
            'team_id' => 0,
            'private_key_id' => 1,
            'deleted_at' => null,
        ];
        if (! $server->exists) {
            $attributes['proxy'] = [
                'type' => ProxyTypes::TRAEFIK->value,
                'status' => ProxyStatus::EXITED->value,
            ];
        }

        $server->forceFill($attributes);
        $server->save();
    }
}
