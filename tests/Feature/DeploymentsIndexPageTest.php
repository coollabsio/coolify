<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments\Index as DeploymentsGlobalIndex;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'My Test App',
    ]);
});

test('deployments index lists team deployment', function () {
    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'global-page-test-uuid',
        'application_id' => $this->application->id,
        'application_name' => $this->application->name,
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'deployment_url' => '/project/'.$this->project->uuid.'/environment/'.$this->environment->uuid.'/application/'.$this->application->uuid.'/deployment/global-page-test-uuid',
        'git_type' => 'github',
    ]);

    Livewire::test(DeploymentsGlobalIndex::class)
        ->assertOk()
        ->assertSee('My Test App')
        ->assertSee('Github');
});

test('deployments index hides other team deployments', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->first();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->first();
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
        'name' => 'Secret Other App',
    ]);

    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'other-team-deployment',
        'application_id' => $otherApplication->id,
        'application_name' => $otherApplication->name,
        'server_id' => $otherServer->id,
        'server_name' => $otherServer->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    Livewire::test(DeploymentsGlobalIndex::class)
        ->assertDontSee('Secret Other App');
});

test('deployments index component renders for authenticated users', function () {
    Livewire::test(DeploymentsGlobalIndex::class)
        ->assertOk()
        ->assertSee('Deployments')
        ->assertSee('History');
});
