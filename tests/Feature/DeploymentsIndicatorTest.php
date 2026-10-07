<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\DeploymentsIndicator;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'Indicator App',
        'status' => 'running',
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'application_name' => $this->application->name,
        'deployment_uuid' => 'deploy-indicator-'.fake()->uuid(),
        'deployment_url' => '/deployments/indicator',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'pull_request_id' => 0,
    ]);
});

it('shows the deployments indicator on every page, including the dashboard', function (string $routeName) {
    $this->get(route($routeName))
        ->assertSuccessful()
        ->assertSee('1 deployment', false)
        ->assertSee('aria-label="Active deployments"', false);
})->with(['dashboard', 'project.index']);

it('hides the indicator when no deployment is running', function () {
    ApplicationDeploymentQueue::query()->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

    Livewire::test(DeploymentsIndicator::class)
        ->assertDontSee('Active deployments');
});

it('does not show deployments of another team', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    ApplicationDeploymentQueue::query()->update(['server_id' => $otherServer->id]);

    Livewire::test(DeploymentsIndicator::class)
        ->assertDontSee('Indicator App')
        ->assertDontSee('Active deployments');
});
