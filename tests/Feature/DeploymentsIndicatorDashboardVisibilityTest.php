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

it('hides the floating deployments indicator on the dashboard', function () {
    $this->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee('1 deployment', false)
        ->assertDontSee('aria-label="Active deployments"', false);
});

it('shows the floating deployments indicator on non-dashboard pages', function () {
    $this->get(route('project.index'))
        ->assertSuccessful()
        ->assertSee('1 deployment', false)
        ->assertSee('aria-label="Active deployments"', false);
});

it('keeps the indicator hidden across polls after mounting on the dashboard', function () {
    $component = Livewire::test(DeploymentsIndicator::class)
        ->set('shouldShow', false)
        ->assertDontSee('1 deployment');

    // Polls call refresh methods without remounting; visibility must stay sticky.
    $component
        ->call('$refresh')
        ->assertSet('shouldShow', false)
        ->assertDontSee('1 deployment');
});

it('updates visibility from the browser path after navigation', function () {
    Livewire::test(DeploymentsIndicator::class)
        ->assertSet('shouldShow', true)
        ->call('updateShouldShowFromPath', '/')
        ->assertSet('shouldShow', false)
        ->assertDontSee('1 deployment')
        ->call('updateShouldShowFromPath', '/projects')
        ->assertSet('shouldShow', true)
        ->assertSee('1 deployment');
});
