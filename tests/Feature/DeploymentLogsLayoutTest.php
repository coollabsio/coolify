<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
});

it('renders deployment log output', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-layout-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([
            [
                'command' => null,
                'output' => 'rolling update started',
                'type' => 'stdout',
                'timestamp' => now()->toISOString(),
                'hidden' => false,
                'batch' => 1,
                'order' => 1,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('rolling update started');
});

it('links deployment commit hashes to the source commit page', function () {
    $githubApp = GithubApp::query()->create([
        'team_id' => $this->team->id,
        'name' => 'GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    $this->application->update([
        'source_id' => $githubApp->id,
        'source_type' => $githubApp->getMorphClass(),
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-commit-link-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => '1234567890abcdef1234567890abcdef12345678',
    ]);

    $response = $this->get(route('project.application.deployment.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee(
        'href="https://github.com/coollabsio/coolify/commit/1234567890abcdef1234567890abcdef12345678" target="_blank" rel="noopener noreferrer"',
        false,
    );
});
