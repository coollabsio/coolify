<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Dashboard\ActiveDeployments;
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
        'name' => 'Dashboard App',
        'status' => 'running',
    ]);
});

function createDashboardDeployment(array $overrides = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create(array_merge([
        'application_id' => test()->application->id,
        'application_name' => test()->application->name,
        'deployment_uuid' => 'deploy-'.fake()->unique()->uuid(),
        'deployment_url' => '/deployments/'.fake()->uuid(),
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ], $overrides));
}

it('shows a visible yellow inset focus state on deployment rows', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard/active-deployments.blade.php'));

    expect(substr_count($view, 'focus-visible:bg-warning/10'))->toBe(4)
        ->and(substr_count($view, 'focus-visible:ring-1'))->toBe(2)
        ->and(substr_count($view, 'focus-visible:ring-inset'))->toBe(2)
        ->and(substr_count($view, 'focus-visible:ring-warning'))->toBe(2)
        ->and(substr_count($view, 'focus-visible:ring-offset-0'))->toBe(2)
        ->and(substr_count($view, 'dark:focus-visible:bg-warning/10'))->toBe(2);
});

it('hides the deployments section when there is nothing to show', function () {
    Livewire::test(ActiveDeployments::class)
        ->assertDontSee('Active and recent deployment activity')
        ->assertDontSee('Running or queued right now')
        ->assertDontSee('Latest completed deployments')
        ->assertDontSee('No active deployments')
        ->assertDontSee('No recent deployments');
});

it('shows only the active section when there are active deployments', function () {
    createDashboardDeployment([
        'application_name' => 'Running Now',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    Livewire::test(ActiveDeployments::class)
        ->assertSee('Deployments')
        ->assertSee('Running or queued right now')
        ->assertSee('Running Now')
        ->assertDontSee('Latest completed deployments')
        ->assertDontSee('No active deployments')
        ->assertDontSee('No recent deployments');
});

it('shows only the recent section when there are recent deployments', function () {
    createDashboardDeployment([
        'application_name' => 'Finished Earlier',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    Livewire::test(ActiveDeployments::class)
        ->assertSee('Deployments')
        ->assertSee('Latest completed deployments')
        ->assertSee('Finished Earlier')
        ->assertDontSee('Running or queued right now')
        ->assertDontSee('No active deployments')
        ->assertDontSee('No recent deployments');
});

it('shows up to five active deployments above recent ones', function () {
    foreach (range(1, 6) as $index) {
        createDashboardDeployment([
            'application_name' => "Active App {$index}",
            'status' => $index % 2 === 0
                ? ApplicationDeploymentStatus::QUEUED->value
                : ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);
    }

    foreach (range(1, 6) as $index) {
        createDashboardDeployment([
            'application_name' => "Recent App {$index}",
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }

    $component = Livewire::test(ActiveDeployments::class);

    expect($component->get('activeDeployments'))->toHaveCount(5)
        ->and($component->get('recentDeployments'))->toHaveCount(5);

    $component
        ->assertSee('Active App')
        ->assertSee('Recent App')
        ->assertSee('Success')
        ->assertSee('In progress')
        ->assertSee('Queued')
        ->assertDontSee('Active App 6');
});

it('does not include active deployments in the recent list', function () {
    createDashboardDeployment([
        'application_name' => 'Running Now',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    createDashboardDeployment([
        'application_name' => 'Finished Earlier',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    createDashboardDeployment([
        'application_name' => 'Failed Earlier',
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);

    $component = Livewire::test(ActiveDeployments::class);

    expect($component->get('activeDeployments')->pluck('application_name')->all())
        ->toBe(['Running Now'])
        ->and($component->get('recentDeployments')->pluck('application_name')->all())
        ->toEqualCanonicalizing(['Finished Earlier', 'Failed Earlier']);

    $component
        ->assertSee('Running Now')
        ->assertSee('Finished Earlier')
        ->assertSee('Failed Earlier')
        ->assertSee('Failed');
});

it('only includes deployments for servers owned by the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

    createDashboardDeployment([
        'application_name' => 'Team Deployment',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);

    ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'application_name' => 'Other Team Deployment',
        'deployment_uuid' => 'deploy-other-'.fake()->uuid(),
        'deployment_url' => '/deployments/other',
        'server_id' => $otherServer->id,
        'server_name' => $otherServer->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ]);

    Livewire::test(ActiveDeployments::class)
        ->assertSee('Team Deployment')
        ->assertDontSee('Other Team Deployment');
});
