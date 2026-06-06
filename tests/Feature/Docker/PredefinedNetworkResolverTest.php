<?php

use App\Livewire\Project\Application\Advanced;
use App\Livewire\Project\Service\StackForm;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Docker\PredefinedNetworkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\InteractsWithDockerNetworks;

uses(RefreshDatabase::class, InteractsWithDockerNetworks::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    $this->withoutVite();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
});

function predefinedNetworkApplication(string $destinationNetwork = 'coolify'): Application
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->where('network', $destinationNetwork)->first();
    if (! $destination) {
        $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::factory()->create([
            'server_id' => $server->id,
            'network' => $destinationNetwork,
        ]));
    }
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $application->settings()->update(['connect_to_docker_network' => true]);

    return $application->refresh()->load('destination.server', 'settings');
}

it('falls back to destination network without explicit selection', function () {
    $application = predefinedNetworkApplication();

    expect(app(PredefinedNetworkResolver::class)->resolve($application))->toBe('coolify');
});

it('resolves persisted eligible network on destination server', function () {
    $application = predefinedNetworkApplication();
    DockerNetwork::create([
        'server_id' => $application->destination->server_id,
        'display_name' => 'Shared',
        'docker_network_name' => 'shared-net',
        'is_active' => true,
    ]);
    $application->settings()->update(['predefined_network' => 'shared-net']);
    $application->load('settings');

    expect(app(PredefinedNetworkResolver::class)->resolve($application))->toBe('shared-net');
});

it('rejects selected network from another server', function () {
    $application = predefinedNetworkApplication();
    $otherServer = Server::factory()->create(['team_id' => $application->destination->server->team_id]);
    DockerNetwork::create([
        'server_id' => $otherServer->id,
        'display_name' => 'Other',
        'docker_network_name' => 'other-net',
        'is_active' => true,
    ]);
    $application->settings()->update(['predefined_network' => 'other-net']);
    $application->load('settings');

    expect(fn () => app(PredefinedNetworkResolver::class)->resolve($application))
        ->toThrow(RuntimeException::class, "Selected predefined network 'other-net' does not exist on the resource server.");
});

it('rejects persisted network after it disappears from runtime inventory', function () {
    $application = predefinedNetworkApplication();
    DockerNetwork::create([
        'server_id' => $application->destination->server_id,
        'display_name' => 'Missing',
        'docker_network_name' => 'missing-net',
        'is_active' => false,
    ]);
    $application->settings()->update(['predefined_network' => 'missing-net']);
    $application->load('settings');

    expect(fn () => app(PredefinedNetworkResolver::class)->resolve($application))
        ->toThrow(RuntimeException::class, "Selected predefined network 'missing-net' does not exist on the resource server.");
});

it('resolves predefined network labels for application settings using alias-first display', function () {
    $application = predefinedNetworkApplication('coolify');
    $application->update(['build_pack' => 'dockercompose']);
    DockerNetwork::create([
        'server_id' => $application->destination->server_id,
        'display_name' => 'Coolify Alias',
        'docker_network_name' => 'coolify',
        'is_active' => true,
    ]);
    DockerNetwork::create([
        'server_id' => $application->destination->server_id,
        'display_name' => 'Shared Alias',
        'docker_network_name' => 'shared-net',
        'is_active' => true,
    ]);
    DockerNetwork::create([
        'server_id' => $application->destination->server_id,
        'display_name' => 'fallback-net',
        'docker_network_name' => 'fallback-net',
        'is_active' => true,
    ]);

    $component = new Advanced;
    $component->application = $application->fresh('destination.server', 'settings');
    $component->mount();

    expect($component->destinationNetworkLabel())->toBe('Coolify Alias')
        ->and($component->eligiblePredefinedNetworks()->pluck('display_name', 'docker_network_name')->all())
        ->toMatchArray([
            'shared-net' => 'Shared Alias',
            'fallback-net' => 'fallback-net',
        ]);
});

it('resolves predefined network labels for services using alias-first display', function () {
    $application = predefinedNetworkApplication('coolify');
    $service = Service::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $application->destination_id,
        'destination_type' => $application->destination_type,
        'connect_to_docker_network' => true,
    ]);
    DockerNetwork::create([
        'server_id' => $service->destination->server_id,
        'display_name' => 'Coolify Alias',
        'docker_network_name' => 'coolify',
        'is_active' => true,
    ]);
    DockerNetwork::create([
        'server_id' => $service->destination->server_id,
        'display_name' => 'Service Shared Alias',
        'docker_network_name' => 'service-shared-net',
        'is_active' => true,
    ]);

    $component = new StackForm;
    $component->service = $service->fresh('destination.server');
    $component->mount();

    expect($component->destinationNetworkLabel())->toBe('Coolify Alias')
        ->and($component->eligiblePredefinedNetworks()->pluck('display_name', 'docker_network_name')->all())
        ->toMatchArray([
            'service-shared-net' => 'Service Shared Alias',
        ]);
});
