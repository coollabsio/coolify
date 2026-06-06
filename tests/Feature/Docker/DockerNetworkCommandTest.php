<?php

use App\Models\Server;
use App\Models\Team;
use App\Services\Docker\DockerNetworkScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails gracefully when server does not exist', function () {
    $this->artisan('coolify:docker-networks:scan', ['server_uuid' => 'nonexistent-uuid'])
        ->expectsOutput('Server not found.')
        ->assertFailed();
});

it('executes scanner and prints summary for existing server', function () {
    $server = Server::factory()->create([
        'name' => 'scan-server',
        'team_id' => Team::factory()->create()->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $server = $server->refresh();

    $scanner = new DockerNetworkScanner(executor: fn (Server $server, array $command): ?string => match (true) {
        str_contains($command[0], 'docker network ls') => implode("\n", [
            json_encode(['Name' => 'coolify']),
            json_encode(['Name' => 'external-net']),
        ]),
        default => json_encode([['Name' => 'coolify', 'Id' => 'abc', 'Driver' => 'bridge', 'Scope' => 'local']]),
    });

    app()->instance(DockerNetworkScanner::class, $scanner);

    $this->artisan('coolify:docker-networks:scan', ['server_uuid' => $server->uuid])
        ->expectsOutput("Scanning Docker networks for server: {$server->name}")
        ->assertSuccessful();
});
