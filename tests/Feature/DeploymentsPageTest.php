<?php

use App\Livewire\Deployments\Index;
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
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

function makeDeploymentForTeam(Team $team, string $applicationName, PrivateKey $privateKey): ApplicationDeploymentQueue
{
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
    // One key for every server: PrivateKey rejects a second row with the same fingerprint.
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->withoutVite();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });
});

it('lists only the current team deployments', function () {
    makeDeploymentForTeam($this->team, 'mine-app', $this->privateKey);
    makeDeploymentForTeam(Team::factory()->create(), 'theirs-app', $this->privateKey);

    $this->get('/deployments')
        ->assertOk()
        ->assertSee('mine-app')
        ->assertDontSee('theirs-app');
});

it('filters deployments by project', function () {
    $mine = makeDeploymentForTeam($this->team, 'first-app', $this->privateKey);
    makeDeploymentForTeam($this->team, 'second-app', $this->privateKey);

    Livewire::test(Index::class)
        ->assertSee('first-app')
        ->assertSee('second-app')
        ->set('project', (string) $mine->application->environment->project_id)
        ->assertSee('first-app')
        ->assertDontSee('second-app');
});

it('forbids a member whose access is limited to some projects', function () {
    $deployment = makeDeploymentForTeam($this->team, 'mine-app', $this->privateKey);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, [
        'role' => 'member',
        'access' => json_encode([
            ['scope' => 'project', 'uuid' => $deployment->application->environment->project->uuid, 'levels' => ['read']],
        ]),
    ]);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get('/deployments')->assertForbidden();
});

it('searches deployments by application name, ignoring case', function () {
    makeDeploymentForTeam($this->team, 'billing-api', $this->privateKey);
    makeDeploymentForTeam($this->team, 'storefront', $this->privateKey);

    Livewire::test(Index::class)
        ->set('search', 'BILLING')
        ->assertSee('billing-api')
        ->assertDontSee('storefront');
});
