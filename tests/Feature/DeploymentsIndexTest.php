<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments\Index as DeploymentsIndex;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);
    $this->serverA = Server::factory()->create(['team_id' => $this->teamA->id]);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);
    $this->serverB = Server::factory()->create(['team_id' => $this->teamB->id]);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

function createDeploymentForEnvironment(Environment $environment, Server $server, string $name): ApplicationDeploymentQueue
{
    $application = Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => StandaloneDocker::factory()->create(['server_id' => $server->id])->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'test-deploy-'.fake()->uuid(),
        'application_name' => $name,
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
}

it('lists deployments from every project in the current team', function () {
    createDeploymentForEnvironment($this->environmentA, $this->serverA, 'app-one');
    $secondProject = Project::factory()->create(['team_id' => $this->teamA->id]);
    $secondEnvironment = Environment::factory()->create(['project_id' => $secondProject->id]);
    createDeploymentForEnvironment($secondEnvironment, $this->serverA, 'app-two');

    Livewire::test(DeploymentsIndex::class)
        ->assertSee('app-one')
        ->assertSee('app-two')
        ->assertViewHas('deployments', fn ($deployments) => $deployments->count() === 2);
});

it('does not list deployments from other teams', function () {
    createDeploymentForEnvironment($this->environmentA, $this->serverA, 'my-app');
    createDeploymentForEnvironment($this->environmentB, $this->serverB, 'other-team-app');

    Livewire::test(DeploymentsIndex::class)
        ->assertSee('my-app')
        ->assertDontSee('other-team-app')
        ->assertViewHas('deployments', fn ($deployments) => $deployments->count() === 1);
});
