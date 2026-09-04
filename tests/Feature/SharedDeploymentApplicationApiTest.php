<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(
        fn () => InstanceSettings::firstOrCreate(['id' => 0])
    );

    $this->ownerTeam = Team::factory()->create();
    $this->deploymentTeam = Team::factory()->create();

    $this->deploymentUser = User::factory()->create();
    $this->deploymentTeam->members()->attach(
        $this->deploymentUser->id,
        ['role' => 'owner']
    );

    session(['currentTeam' => $this->deploymentTeam]);

    $this->token = $this->deploymentUser->createToken(
        'shared-deployment-test-token',
        ['*']
    );
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create([
        'team_id' => $this->ownerTeam->id,
    ]);

    $this->server->settings()->update([
        'is_build_server' => false,
        'is_reachable' => true,
        'is_usable' => true,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    $this->destination = $this->server
        ->standaloneDockers()
        ->firstOrFail();

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'shared-deployment-project',
        'team_id' => $this->deploymentTeam->id,
    ]);

    $this->environment = $this->project->environments()->first();
});

function sharedDeploymentApplicationHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function sharedDeploymentApplicationPayload($test): array
{
    return [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'server_uuid' => $test->server->uuid,
        'destination_uuid' => $test->destination->uuid,
        'git_repository' => 'https://gitlab.com/coolify/shared-deployment-test',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'autogenerate_domain' => false,
    ];
}

test('team can create an application on an authorized shared deployment server', function () {
    $this->server->sharedTeams()->attach($this->deploymentTeam->id, [
        'can_build' => false,
        'can_deploy' => true,
    ]);

    $response = $this
        ->withHeaders(
            sharedDeploymentApplicationHeaders($this->bearerToken)
        )
        ->postJson(
            '/api/v1/applications/public',
            sharedDeploymentApplicationPayload($this)
        );

    $response->assertCreated();

    $application = Application::query()
        ->where('uuid', $response->json('uuid'))
        ->with('environment.project', 'destination.server')
        ->firstOrFail();

    expect($application->environment->project->team_id)
        ->toBe($this->deploymentTeam->id)
        ->and($application->destination->server_id)
        ->toBe($this->server->id)
        ->and($application->destination->server->team_id)
        ->toBe($this->ownerTeam->id);
});

test('team cannot create an application without shared deployment access', function () {
    $response = $this
        ->withHeaders(
            sharedDeploymentApplicationHeaders($this->bearerToken)
        )
        ->postJson(
            '/api/v1/applications/public',
            sharedDeploymentApplicationPayload($this)
        );

    $response
        ->assertNotFound()
        ->assertJson([
            'message' => 'Server not found.',
        ]);

    expect(Application::query()->count())->toBe(0);
});

test('build-only sharing does not allow application creation', function () {
    $this->server->sharedTeams()->attach($this->deploymentTeam->id, [
        'can_build' => true,
        'can_deploy' => false,
    ]);

    $response = $this
        ->withHeaders(
            sharedDeploymentApplicationHeaders($this->bearerToken)
        )
        ->postJson(
            '/api/v1/applications/public',
            sharedDeploymentApplicationPayload($this)
        );

    $response->assertNotFound();

    expect(Application::query()->count())->toBe(0);
});
