<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

function makeDeploymentForTeam(Team $team, string $applicationName): ApplicationDeploymentQueue
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'net-'.$server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'name' => $applicationName,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $applicationName,
        'deployment_uuid' => (string) new Cuid2,
        'deployment_url' => '/deployments',
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => 'finished',
        'git_type' => 'github',
    ]);
}

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

it('lists only the current team deployments', function () {
    makeDeploymentForTeam($this->team, 'mine-app');
    makeDeploymentForTeam(Team::factory()->create(), 'theirs-app');

    $this->get('/deployments')
        ->assertOk()
        ->assertSee('mine-app')
        ->assertDontSee('theirs-app');
});
