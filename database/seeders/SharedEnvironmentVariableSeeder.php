<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Seeder;

class SharedEnvironmentVariableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'team_env',
            'type' => 'team',
            'team_id' => 0,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'env_env',
            'type' => 'environment',
            'environment_id' => 1,
            'team_id' => 0,
        ]);
        SharedEnvironmentVariable::create([
            'key' => 'NODE_ENV',
            'value' => 'project_env',
            'type' => 'project',
            'project_id' => 1,
            'team_id' => 0,
        ]);

        // Add predefined server variables to all existing servers
        $servers = \App\Models\Server::all();
        foreach ($servers as $server) {
            SharedEnvironmentVariable::firstOrCreate([
                'key' => 'COOLIFY_SERVER_UUID',
                'type' => 'server',
                'server_id' => $server->id,
                'team_id' => $server->team_id,
            ], [
                'value' => $server->uuid,
                'is_literal' => true,
            ]);

            SharedEnvironmentVariable::firstOrCreate([
                'key' => 'COOLIFY_SERVER_NAME',
                'type' => 'server',
                'server_id' => $server->id,
                'team_id' => $server->team_id,
            ], [
                'value' => $server->name,
                'is_literal' => true,
            ]);
        }
    }
}
