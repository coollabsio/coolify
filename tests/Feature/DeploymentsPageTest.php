<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

/**
 * @param  array{
 *     status?: string,
 *     application_name?: string,
 *     server_name?: string,
 *     server_id?: int,
 *     git_type?: string,
 *     deployment_url?: string|null,
 *     commit_message?: string,
 *     deployment_uuid?: string
 * }  $deploymentOverrides
 * @param  array{name?: string}  $applicationOverrides
 * @param  array{name?: string}  $projectOverrides
 */
function createDeploymentForTeam(Team $team, array $deploymentOverrides = [], array $applicationOverrides = [], array $projectOverrides = []): ApplicationDeploymentQueue
{
    $project = Project::factory()->create(array_merge([
        'team_id' => $team->id,
        'name' => 'Alpha Project',
    ], $projectOverrides));

    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);

    $application = Application::factory()->create(array_merge([
        'environment_id' => $environment->id,
        'name' => 'Alpha App',
    ], $applicationOverrides));

    return ApplicationDeploymentQueue::query()->create(array_merge([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->lexify('dep-'.$application->id.'-????????'),
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'application_name' => $application->name,
        'server_name' => 'Main Server',
        'server_id' => 101,
        'git_type' => 'github',
        'deployment_url' => '/project/test/environment/test/application/test/deployment',
        'commit_message' => 'Deploy alpha app',
    ], $deploymentOverrides));
}

it('normalizes trimmed status filter values when listing and applying status filters', function () {
    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ], [
        'name' => 'Canonical Status App',
    ], [
        'name' => 'Canonical Status Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ' queued ',
    ], [
        'name' => 'Spaced Status App',
    ], [
        'name' => 'Spaced Status Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ], [
        'name' => 'Failed Status App',
    ], [
        'name' => 'Failed Status Project',
    ]);

    $response = $this->get('/deployments?status='.ApplicationDeploymentStatus::QUEUED->value);

    $response->assertOk();
    $response->assertSee('Canonical Status App');
    $response->assertSee('Spaced Status App');
    $response->assertDontSee('Failed Status App');

    Livewire::test(Deployments::class)
        ->set('status', ApplicationDeploymentStatus::QUEUED->value)
        ->assertSee('Canonical Status App')
        ->assertSee('Spaced Status App')
        ->assertDontSee('Failed Status App');
});

it('shows deployments page link in the main navbar', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Deployments');
    $response->assertSee('/deployments', false);
});

it('lists deployments belonging to the current team', function () {
    createDeploymentForTeam($this->team);
    createDeploymentForTeam($this->team, [
        'status' => 'finished',
        'server_name' => 'Build Server',
    ], [
        'name' => 'Beta App',
    ], [
        'name' => 'Beta Project',
    ]);

    $otherTeam = Team::factory()->create();
    createDeploymentForTeam($otherTeam, [], ['name' => 'Hidden App'], ['name' => 'Hidden Project']);

    $response = $this->get('/deployments');

    $response->assertOk();
    $response->assertSee('Alpha App');
    $response->assertSee('Beta App');
    $response->assertDontSee('Hidden App');
    $response->assertSee('Alpha Project');
    $response->assertSee('Beta Project');
});

it('filters deployments by status, project, server, and source', function () {
    createDeploymentForTeam($this->team, [
        'status' => 'queued',
        'server_name' => 'Queue Server',
        'git_type' => 'github',
    ], [
        'name' => 'Queued App',
    ], [
        'name' => 'Queued Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => 'failed',
        'server_name' => 'Fail Server',
        'git_type' => 'gitlab',
    ], [
        'name' => 'Failed App',
    ], [
        'name' => 'Failed Project',
    ]);

    $response = $this->get('/deployments?status=queued&project=Queued+Project&server=Queue+Server&source=github');

    $response->assertOk();
    $response->assertSee('Queued App');
    $response->assertDontSee('Failed App');

    Livewire::test(Deployments::class)
        ->set('status', 'queued')
        ->set('project', 'Queued Project')
        ->set('server', 'Queue Server')
        ->set('source', 'github')
        ->assertSee('Queued App')
        ->assertDontSee('Failed App');
});

it('hides server and source filters when there is only one choice', function () {
    createDeploymentForTeam($this->team, [
        'status' => 'queued',
        'server_name' => 'Solo Server',
        'git_type' => 'github',
    ], [
        'name' => 'Solo App',
    ], [
        'name' => 'Solo Project',
    ]);

    $response = $this->get('/deployments');

    $response->assertOk();
    $response->assertSee('All projects');
    $response->assertSee('All statuses');
    $response->assertDontSee('All servers');
    $response->assertDontSee('All sources');
});

it('ignores hidden server and source query filters', function () {
    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'server_name' => 'Solo Server',
        'git_type' => 'github',
    ], [
        'name' => 'Solo App',
    ], [
        'name' => 'Solo Project',
    ]);

    $response = $this->get('/deployments?server=Skynet&source=gitlab');

    $response->assertOk();
    $response->assertSee('Solo App');
    $response->assertDontSee('All servers');
    $response->assertDontSee('All sources');
});

it('applies filters when query string values are literal zero strings', function () {
    createDeploymentForTeam($this->team, [
        'status' => '0',
        'server_name' => '0',
        'git_type' => '0',
    ], [
        'name' => 'Zero App',
    ], [
        'name' => '0',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'server_name' => 'Fail Server',
        'git_type' => 'gitlab',
    ], [
        'name' => 'Non Zero App',
    ], [
        'name' => 'Non Zero Project',
    ]);

    $response = $this->get('/deployments?status=0&project=0&server=0&source=0');

    $response->assertOk();
    $response->assertSee('Zero App');
    $response->assertDontSee('Non Zero App');
});

it('normalizes trimmed filter values before deciding whether to show filter controls', function () {
    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'server_name' => 'Solo Server',
        'git_type' => 'github',
    ], [
        'name' => 'Alpha App',
    ], [
        'name' => 'Alpha Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'server_name' => ' Solo Server ',
        'git_type' => ' github ',
    ], [
        'name' => 'Beta App',
    ], [
        'name' => 'Beta Project',
    ]);

    $response = $this->get('/deployments');

    $response->assertOk();
    $response->assertSee('Alpha App');
    $response->assertSee('Beta App');
    $response->assertDontSee('All servers');
    $response->assertDontSee('All sources');
});

it('keeps all project options available while other filters are active', function () {
    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'server_name' => 'Queue Server',
        'git_type' => 'github',
    ], [
        'name' => 'Queued App',
    ], [
        'name' => 'Queued Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'server_name' => 'Fail Server',
        'git_type' => 'gitlab',
    ], [
        'name' => 'Failed App',
    ], [
        'name' => 'Failed Project',
    ]);

    $response = $this->get('/deployments?status='.ApplicationDeploymentStatus::QUEUED->value);

    $response->assertOk();
    $response->assertSee('Queued App');
    $response->assertDontSee('Failed App');
    $response->assertSee('Queued Project');
    $response->assertSee('Failed Project');
});

it('normalizes project filter values when listing and applying project filters', function () {
    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ], [
        'name' => 'Canonical App',
    ], [
        'name' => 'Alpha Project',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ], [
        'name' => 'Spaced App',
    ], [
        'name' => ' Alpha Project ',
    ]);

    createDeploymentForTeam($this->team, [
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ], [
        'name' => 'Different Project App',
    ], [
        'name' => 'Beta Project',
    ]);

    Livewire::test(Deployments::class)
        ->assertDontSee('All servers')
        ->assertDontSee('All sources')
        ->set('project', 'Alpha Project')
        ->assertSee('Canonical App')
        ->assertSee('Spaced App')
        ->assertDontSee('Different Project App');
});
