<?php

use App\Livewire\Project\Shared\ContainerInfo;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->withoutVite();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
});

it('renders the container info page for an application', function () {
    $this->get(route('project.application.container-info', [
        'project_uuid' => $this->application->environment->project->uuid,
        'environment_uuid' => $this->application->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]))->assertOk()->assertSee('Container Info');
});

it('shows docker inspect details and networks for each container', function () {
    $name = $this->application->uuid;
    $inspect = json_encode([
        'Id' => str_repeat('a', 64),
        'Name' => "/{$name}",
        'Image' => 'sha256:'.str_repeat('b', 64),
        'Created' => '2026-09-01T10:00:00.000000000Z',
        'RestartCount' => 2,
        'Config' => ['Image' => 'nginx:1.27'],
        'State' => ['Status' => 'running', 'StartedAt' => '2026-09-02T11:30:00.000000000Z'],
        'NetworkSettings' => ['Networks' => [
            'coolify' => ['IPAddress' => '172.18.0.5', 'GlobalIPv6Address' => 'fd00::5', 'Gateway' => '172.18.0.1', 'MacAddress' => '02:42:ac:12:00:05', 'Aliases' => ['web']],
        ]],
    ]);
    Process::fake([
        '*docker ps*' => Process::result(output: json_encode(['ID' => 'aaaaaaaaaaaa', 'Names' => $name, 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0'])),
        '*docker container inspect*' => Process::result(output: $inspect),
        '*docker network ls*' => Process::result(output: "bridge\ncoolify\nhost\nnone\nother-net\n"),
    ]);

    Livewire::test(ContainerInfo::class, ['resource' => $this->application])
        ->call('load')
        ->assertSet('loaded', true)
        ->assertSet('containers.0.name', $name)
        ->assertSet('containers.0.short_id', 'aaaaaaaaaaaa')
        ->assertSet('containers.0.image', 'nginx:1.27')
        ->assertSet('containers.0.restart_count', 2)
        ->assertSet('containers.0.created', '2026-09-01 10:00:00 UTC')
        ->assertSet('containers.0.networks.0.ipv4', '172.18.0.5')
        ->assertSet('containers.0.networks.0.ipv6', 'fd00::5')
        ->assertSet('containers.0.networks.0.mac', '02:42:ac:12:00:05')
        ->assertSet('availableNetworks', ['coolify', 'other-net'])
        ->assertSet("selectedNetwork.{$name}", 'other-net')
        ->assertSee('172.18.0.5')
        ->assertSee('other-net');
});

it('refuses to disconnect the only network of a container', function () {
    $name = $this->application->uuid;
    Process::fake(['*' => Process::result(output: '')]);

    Livewire::test(ContainerInfo::class, ['resource' => $this->application])
        ->set('containers', [[
            'id' => str_repeat('a', 64), 'short_id' => 'aaaaaaaaaaaa', 'name' => $name, 'image' => 'nginx', 'image_id' => 'sha256:b',
            'status' => 'running', 'restart_count' => 0, 'created' => null, 'started' => null,
            'networks' => [['name' => 'coolify', 'ipv4' => null, 'ipv6' => null, 'gateway' => null, 'mac' => null, 'aliases' => '']],
            'network_names' => ['coolify'],
        ]])
        ->call('disconnect', $name, 'coolify')
        ->assertDispatched('error');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'docker network disconnect'));
});
