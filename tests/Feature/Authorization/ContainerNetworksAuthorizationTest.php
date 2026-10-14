<?php

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

function containerNetworkTestState(): object
{
    return $GLOBALS['container_network_test_state'];
}

function getCurrentApplicationContainerStatus(Server $server, int $id, ?int $pullRequestId = null, ?bool $includePullrequests = false): Collection
{
    $state = containerNetworkTestState();
    $state->lookups[] = ['server_id' => $server->id, 'application_id' => $id];

    return collect([[
        'ID' => $state->containerIds[$server->id],
        'Names' => "untrusted-name-{$server->id}",
    ]]);
}

function getContainerStatus(Server $server, string $container_id, bool $all_data = false, bool $throwError = false): mixed
{
    $state = containerNetworkTestState();
    $state->inspects[] = ['server_id' => $server->id, 'container_id' => $container_id];
    $networkSettings = [];
    foreach ($state->containerNetworks[$container_id] ?? [] as $network) {
        $networkSettings[$network] = [];
    }

    return [
        'Id' => $container_id,
        'NetworkSettings' => ['Networks' => $networkSettings],
    ];
}

function instant_remote_process(Collection|array $commands, Server $server, bool $throwError = true): ?string
{
    $state = containerNetworkTestState();
    $command = $commands instanceof Collection ? $commands->implode("\n") : implode("\n", $commands);
    $state->commands[] = ['server_id' => $server->id, 'command' => $command];

    if (str_contains($command, 'docker network ls')) {
        return collect($state->remoteNetworks)
            ->map(fn (array $network): string => json_encode($network, JSON_THROW_ON_ERROR))
            ->implode("\n");
    }

    return null;
}

function format_docker_command_output_to_json($rawOutput): Collection
{
    return collect([
        ['Name' => 'team-a-default', 'Driver' => 'bridge'],
        ['Name' => 'team-a-shared', 'Driver' => 'bridge'],
        ['Name' => 'team-b-secret', 'Driver' => 'bridge'],
    ]);
}

function handleError(\Throwable $e, $component): void
{
    throw $e;
}

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->otherServer = Server::factory()->create(['team_id' => $this->otherTeam->id]);

    foreach ([$this->server, $this->otherServer] as $server) {
        $server->settings->update([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
            'is_swarm_manager' => false,
            'is_swarm_worker' => false,
        ]);
        $server->setRelation('settings', $server->settings->fresh());
    }

    Server::flushIdentityMap();
    $this->server = Server::findOrFail($this->server->id);
    $this->otherServer = Server::findOrFail($this->otherServer->id);

    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->destination->update(['network' => 'team-a-default']);
    $this->otherDestination = StandaloneDocker::where('server_id', $this->otherServer->id)->firstOrFail();
    $this->otherDestination->update(['network' => 'team-b-secret']);
    $this->sharedDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'team-a-shared',
    ]);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $this->otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->otherDestination->id,
        'destination_type' => $this->otherDestination->getMorphClass(),
    ]);

    $GLOBALS['container_network_test_state'] = (object) [
        'containerIds' => [
            $this->server->id => 'container-a',
            $this->otherServer->id => 'container-b',
        ],
        'containerNetworks' => [
            'container-a' => ['team-a-default'],
            'container-b' => ['team-b-secret'],
        ],
        'remoteNetworks' => [
            ['Name' => 'team-a-default', 'Driver' => 'bridge'],
            ['Name' => 'team-a-shared', 'Driver' => 'bridge'],
            ['Name' => 'team-b-secret', 'Driver' => 'bridge'],
        ],
        'commands' => [],
        'inspects' => [],
        'lookups' => [],
    ];

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('denies cross-team resource access', function () {
    Livewire::test(ContainerNetworks::class, ['resource' => $this->otherApplication])
        ->assertForbidden();
});

it('denies members permission to mutate networks', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(ContainerNetworks::class, ['resource' => $this->application])
        ->set('selectedNetwork', 'team-a-shared')
        ->call('connectNetwork')
        ->assertForbidden();
});

it('rejects a tampered cross-team selected network', function () {
    Livewire::test(ContainerNetworks::class, ['resource' => $this->application])
        ->set('availableNetworks', collect([['name' => 'team-b-secret', 'driver' => 'bridge']]))
        ->set('connectedNetworks', collect(['team-a-default']))
        ->set('selectedNetwork', 'team-b-secret')
        ->call('connectNetwork')
        ->assertSuccessful();

    $commands = collect(containerNetworkTestState()->commands)
        ->pluck('command')
        ->filter(fn (string $command): bool => str_contains($command, 'docker network connect'));

    expect($commands)->toBeEmpty();
});

it('re-fetches attached networks before disconnecting', function () {
    Livewire::test(ContainerNetworks::class, ['resource' => $this->application])
        ->set('connectedNetworks', collect(['team-b-secret']))
        ->call('disconnectNetwork', 'team-b-secret')
        ->assertSuccessful();

    $commands = collect(containerNetworkTestState()->commands)
        ->pluck('command')
        ->filter(fn (string $command): bool => str_contains($command, 'docker network disconnect'));

    expect($commands)->toBeEmpty();
});

it('re-resolves the server container and supported state for mutations', function () {
    Livewire::test(ContainerNetworks::class, ['resource' => $this->application])
        ->set('server', $this->otherServer)
        ->set('isSupported', false)
        ->set('selectedNetwork', 'team-a-shared')
        ->call('connectNetwork')
        ->assertSuccessful();

    $mutation = collect(containerNetworkTestState()->commands)
        ->first(fn (array $entry): bool => str_contains($entry['command'], 'docker network connect'));

    expect($mutation)->not->toBeNull()
        ->and($mutation['server_id'])->toBe($this->server->id)
        ->and($mutation['command'])->toContain('"team-a-shared" "container-a"')
        ->not->toContain('untrusted-name');
});
