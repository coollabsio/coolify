<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Team\Index as TeamIndex;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });
});

test('renaming the team refreshes the team switcher', function () {
    Livewire::test(TeamIndex::class)
        ->set('name', 'Renamed team')
        ->call('submit')
        ->assertDispatched('team-updated');

    expect($this->team->fresh()->name)->toBe('Renamed team');
});

test('renaming an application sends the new name to the breadcrumb', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
    $application = Application::factory()->create([
        'name' => 'Old app',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'ports_exposes' => '80',
    ]);

    Livewire::test(General::class, ['application' => $application->fresh()])
        ->set('name', 'New app')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('name-changed', uuid: $application->uuid, name: 'New app');
});
