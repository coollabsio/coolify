<?php

use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Server::query()->chunk(100, function ($servers) {
            foreach ($servers as $server) {
                $existingKeys = SharedEnvironmentVariable::where('type', 'server')
                    ->where('server_id', $server->id)
                    ->whereIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])
                    ->pluck('key')
                    ->toArray();

                if (! in_array('COOLIFY_SERVER_UUID', $existingKeys)) {
                    SharedEnvironmentVariable::create([
                        'key' => 'COOLIFY_SERVER_UUID',
                        'value' => $server->uuid,
                        'type' => 'server',
                        'server_id' => $server->id,
                        'team_id' => $server->team_id,
                        'is_literal' => true,
                    ]);
                }

                if (! in_array('COOLIFY_SERVER_NAME', $existingKeys)) {
                    SharedEnvironmentVariable::create([
                        'key' => 'COOLIFY_SERVER_NAME',
                        'value' => $server->name,
                        'type' => 'server',
                        'server_id' => $server->id,
                        'team_id' => $server->team_id,
                        'is_literal' => true,
                    ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SharedEnvironmentVariable::where('type', 'server')
            ->whereIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])
            ->delete();
    }
};
