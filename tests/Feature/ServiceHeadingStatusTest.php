<?php

use App\Livewire\Project\Service\Heading as ServiceHeading;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Status Project',
        'team_id' => $this->team->id,
    ]);
    $environment = $project->environments()->first();

    $this->service = Service::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Status Service',
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => 'services: {}',
    ]);

    $this->runningApp = ServiceApplication::create(['name' => 'web', 'service_id' => $this->service->id]);
    $this->runningApp->forceFill(['status' => 'running:healthy'])->save();
    $this->exitedApp = ServiceApplication::create(['name' => 'worker', 'service_id' => $this->service->id]);
    $this->exitedApp->forceFill(['status' => 'exited'])->save();

    $this->parameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $this->service->uuid,
    ];
});

test('the heading shows the status of the selected service resource', function () {
    $service = $this->service->fresh();
    expect($service->status)->not->toBe($this->exitedApp->status);

    Livewire::test(ServiceHeading::class, [
        'service' => $service,
        'parameters' => $this->parameters + ['stack_service_uuid' => $this->exitedApp->uuid],
        'query' => [],
    ])
        ->assertSee('Resource status')
        ->assertDontSee('Service status')
        ->assertSeeInOrder(['Resource status', 'Container', 'Exited', 'Healthcheck'])
        ->assertDontSee('Degraded');
});

test('the heading shows the service status when no resource is selected', function () {
    Livewire::test(ServiceHeading::class, [
        'service' => $this->service->fresh(),
        'parameters' => $this->parameters,
        'query' => [],
    ])
        ->assertSee('Service status')
        ->assertDontSee('Resource status')
        ->assertSeeInOrder(['Service status', 'Containers', 'Healthcheck'])
        ->assertSee('Degraded');
});
