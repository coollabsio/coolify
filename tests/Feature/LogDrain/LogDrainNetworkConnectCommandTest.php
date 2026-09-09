<?php

use App\Actions\Server\StartLogDrain;
use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reflectedLogDrainNetworkCommands(object $action, Server|Service $model): array
{
    $method = new ReflectionMethod($action, 'logDrainNetworkConnectCommands');
    $method->setAccessible(true);

    return $method->invoke($action, $model);
}

function createServerWithTeam(): Server
{
    $team = Team::factory()->create();

    return Server::factory()->create(['team_id' => $team->id]);
}

function createServiceOnServer(Server $server, string $network, bool $connectToDockerNetwork = true): Service
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::query()->firstOrCreate(
        ['server_id' => $server->id, 'network' => $network],
        ['uuid' => fake()->uuid(), 'name' => fake()->unique()->word()]
    );

    return Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'connect_to_docker_network' => $connectToDockerNetwork,
        'docker_compose' => "services:\n  signoz:\n    image: signoz/signoz:latest\n",
    ]);
}

it('connects the log drain container to a service preferred network when the server log drain is enabled', function () {
    $server = createServerWithTeam();
    $server->settings()->update(['is_logdrain_custom_enabled' => true]);
    $service = createServiceOnServer($server, 'signoz-net', true);

    $commands = reflectedLogDrainNetworkCommands(new StartService, $service->fresh(['destination.server.settings']));

    expect($commands)->toContain("docker network connect 'signoz-net' coolify-log-drain >/dev/null 2>&1 || true");
});

it('does not connect the log drain container when service preferred network is disabled', function () {
    $server = createServerWithTeam();
    $server->settings()->update(['is_logdrain_custom_enabled' => true]);
    $service = createServiceOnServer($server, 'signoz-net', false);

    $commands = reflectedLogDrainNetworkCommands(new StartService, $service->fresh(['destination.server.settings']));

    expect($commands)->toBeEmpty();
});

it('does not connect the log drain container when the server log drain is disabled', function () {
    $server = createServerWithTeam();
    $service = createServiceOnServer($server, 'signoz-net', true);

    $commands = reflectedLogDrainNetworkCommands(new StartService, $service->fresh(['destination.server.settings']));

    expect($commands)->toBeEmpty();
});

it('connects a restarted log drain container to all enabled service preferred networks on the server', function () {
    $server = createServerWithTeam();
    $server->settings()->update(['is_logdrain_custom_enabled' => true]);
    createServiceOnServer($server, 'signoz-net', true);
    createServiceOnServer($server, 'ignored-net', false);
    createServiceOnServer($server, 'signoz-net', true);

    $otherServer = createServerWithTeam();
    createServiceOnServer($otherServer, 'other-server-net', true);

    $commands = reflectedLogDrainNetworkCommands(new StartLogDrain, $server->fresh(['settings']));

    expect($commands)
        ->toContain("docker network connect 'signoz-net' coolify-log-drain >/dev/null 2>&1 || true")
        ->not->toContain("docker network connect 'ignored-net' coolify-log-drain >/dev/null 2>&1 || true")
        ->not->toContain("docker network connect 'other-server-net' coolify-log-drain >/dev/null 2>&1 || true");

    expect($commands)->toHaveCount(1);
});
