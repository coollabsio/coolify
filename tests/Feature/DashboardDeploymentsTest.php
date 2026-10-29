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
use Illuminate\Support\Facades\DB;
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

it('hides the deployments section when there is nothing to show', function () {
    Livewire::test(ActiveDeployments::class)
        ->assertDontSee('Running or queued right now')
        ->assertDontSee('Recent deployments')
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
        ->assertDontSee('Recent deployments')
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
        ->assertSee('Recent deployments')
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

it('only includes deployments of applications owned by the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    createDashboardDeployment([
        'application_name' => 'Team Deployment',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    createDashboardDeployment([
        'application_id' => $otherApplication->id,
        'application_name' => 'Other Team Deployment',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    Livewire::test(ActiveDeployments::class)
        ->assertSee('Team Deployment')
        ->assertDontSee('Other Team Deployment')
        ->assertDontSee('Running or queued right now');
});

it('does not load deployment payload columns when the dashboard list polls', function () {
    createDashboardDeployment([
        'logs' => json_encode([['output' => str_repeat('large deployment log ', 20000)]]),
        'configuration_snapshot' => ['docker_compose_raw' => str_repeat('x', 100000)],
        'configuration_diff' => ['fqdn' => ['old' => null, 'new' => 'https://example.com']],
    ]);
    createDashboardDeployment([
        'application_name' => 'Dashboard App',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'logs' => json_encode([['output' => str_repeat('large deployment log ', 20000)]]),
    ]);

    $deploymentQueries = [];
    DB::listen(function ($query) use (&$deploymentQueries) {
        if (str_contains($query->sql, 'application_deployment_queues')) {
            $deploymentQueries[] = $query->sql;
        }
    });

    Livewire::test(ActiveDeployments::class)
        ->assertSee('Dashboard App')
        ->call('refreshDeployments')
        ->assertSee('Dashboard App')
        ->call('$refresh')
        ->assertSee('Running or queued right now')
        ->assertSee('Recent deployments');

    expect($deploymentQueries)->not->toBeEmpty();
    foreach ($deploymentQueries as $sql) {
        expect($sql)->not->toMatch('/select\s+("?\w+"?\.)?\*/i')
            ->not->toContain('logs')
            ->not->toContain('configuration_snapshot')
            ->not->toContain('configuration_diff');
    }
});

function createDashboardApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ], $overrides));
}

it('lists the five newest eligible deployments of the team across its applications', function () {
    $secondApplication = createDashboardApplication();
    $thirdApplication = createDashboardApplication();
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherApplication = createDashboardApplication([
        'environment_id' => Environment::factory()->create(['project_id' => $otherProject->id])->id,
    ]);

    $expected = [];
    foreach (range(1, 4) as $round) {
        foreach ([$this->application, $secondApplication, $thirdApplication] as $application) {
            $expected[] = createDashboardDeployment([
                'application_id' => $application->id,
                'status' => $round % 2 === 0
                    ? ApplicationDeploymentStatus::FAILED->value
                    : ApplicationDeploymentStatus::FINISHED->value,
            ])->id;
        }
    }
    createDashboardDeployment(['application_id' => $otherApplication->id]);
    createDashboardDeployment(['application_id' => $secondApplication->id, 'status' => ApplicationDeploymentStatus::QUEUED->value]);
    createDashboardDeployment(['application_id' => $thirdApplication->id, 'status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    $component = Livewire::test(ActiveDeployments::class);

    expect($component->get('recentDeployments')->pluck('id')->all())
        ->toBe(array_slice(array_reverse($expected), 0, 5));
});

it('lists fewer than five recent deployments when fewer are eligible', function () {
    $finished = createDashboardDeployment()->id;
    $failed = createDashboardDeployment(['status' => ApplicationDeploymentStatus::FAILED->value])->id;
    createDashboardDeployment(['status' => ApplicationDeploymentStatus::QUEUED->value]);

    expect(Livewire::test(ActiveDeployments::class)->get('recentDeployments')->pluck('id')->all())
        ->toBe([$failed, $finished]);
});

it('keeps deployments of deleted applications and skips deployments without an application', function () {
    $deletedApplication = createDashboardApplication();
    $deployment = createDashboardDeployment([
        'application_id' => $deletedApplication->id,
        'application_name' => 'Deleted App Deployment',
    ]);
    $deletedApplication->delete();
    createDashboardDeployment([
        'application_id' => '999999',
        'application_name' => 'Orphan Deployment',
    ]);

    $component = Livewire::test(ActiveDeployments::class);

    expect($component->get('recentDeployments')->pluck('id')->all())->toBe([$deployment->id])
        ->and($component->get('recentDeployments')->first()->application)->toBeNull();
    $component->assertSee('Deleted App Deployment')->assertDontSee('Orphan Deployment');
});

it('moves a deployment from the active to the recent list when its status changes', function () {
    $deployment = createDashboardDeployment([
        'application_name' => 'Status Change App',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    $component = Livewire::test(ActiveDeployments::class);
    expect($component->get('activeDeployments')->pluck('id')->all())->toBe([$deployment->id])
        ->and($component->get('recentDeployments'))->toBeEmpty();

    $deployment->setStatus(ApplicationDeploymentStatus::FINISHED->value);
    $component->call('refreshDeployments');

    expect($component->get('activeDeployments'))->toBeEmpty()
        ->and($component->get('recentDeployments')->pluck('id')->all())->toBe([$deployment->id]);
    $component->assertDontSee('Running or queued right now')->assertSee('Recent deployments');
});

it('looks up the team applications once per request without loading full applications', function () {
    createDashboardDeployment();
    createDashboardDeployment(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

    $applicationQueries = [];
    DB::listen(function ($query) use (&$applicationQueries) {
        if (str_contains($query->sql, 'from "applications"')) {
            $applicationQueries[] = $query->sql;
        }
    });

    Livewire::test(ActiveDeployments::class)->assertSee('Dashboard App');

    $teamLookups = array_filter($applicationQueries, fn ($sql) => str_contains($sql, 'exists'));
    expect($teamLookups)->toHaveCount(1);
    foreach ($applicationQueries as $sql) {
        expect($sql)->not->toMatch('/^select\s+("?\w+"?\.)?\*/i')
            ->not->toContain('additional_servers_count')
            ->not->toContain('additional_networks_count');
    }
});
