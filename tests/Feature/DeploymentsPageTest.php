<?php

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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'server-a']);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'project-a']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'app-a',
        'status' => 'running',
    ]);

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'commit' => 'deadbeef',
        'status' => 'finished',
        'server_id' => $this->server->id,
        'application_name' => $this->application->name,
        'server_name' => $this->server->name,
        'deployment_url' => '/fake/deployment/url',
        'is_webhook' => false,
        'is_api' => false,
        'pull_request_id' => 0,
        'force_rebuild' => false,
        'restart_only' => false,
        'only_this_server' => false,
        'rollback' => false,
    ]);
});

it('renders the global deployments page', function () {
    $this->withoutVite();

    $response = $this->get(route('deployments.index'));

    $response->assertSuccessful();
    $response->assertSee('Deployments');
    $response->assertSee('app-a');
    $response->assertSee('project-a');
    $response->assertSee('server-a');
});
