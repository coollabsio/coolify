<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'original-name',
    ]);

    Queue::fake();
});

function instantDeployHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'instant-deploy-scope-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken];
}

test('write-only token can update an application without deployment', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['name' => 'edited-name'])
        ->assertOk();

    expect($this->application->fresh()->name)->toBe('edited-name');
    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('write-only token cannot deploy through application update or partially save changes', function () {
    $originalIsSpa = $this->application->settings->is_spa;

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'name' => 'edited-name',
            'post_deployment_command' => 'echo should-not-run',
            'is_spa' => ! $originalIsSpa,
            'instant_deploy' => true,
        ])->assertForbidden();

    $application = $this->application->fresh();
    expect($application->name)->toBe('original-name')
        ->and($application->post_deployment_command)->toBeNull()
        ->and($application->settings->is_spa)->toBe($originalIsSpa);
    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('write-only token cannot bypass deploy ability with a truthy string flag', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'instant_deploy' => 'false',
        ])->assertForbidden();

    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('write-only token cannot use the explicit start endpoint', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson("/api/v1/applications/{$this->application->uuid}/start")
        ->assertForbidden();

    expect(ApplicationDeploymentQueue::count())->toBe(0);
});

test('write and deploy token can update and queue an application deployment', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'name' => 'edited-name',
            'instant_deploy' => true,
        ])->assertOk();

    expect($this->application->fresh()->name)->toBe('edited-name');
    expect(ApplicationDeploymentQueue::count())->toBe(1);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

test('root token can update and queue an application deployment', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['root']))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'instant_deploy' => true,
        ])->assertOk();

    expect(ApplicationDeploymentQueue::count())->toBe(1);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

test('write-only token cannot create and deploy a docker image application', function () {
    $before = Application::count();

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'alpine',
            'instant_deploy' => true,
        ])->assertForbidden();

    expect(Application::count())->toBe($before);
    expect(ApplicationDeploymentQueue::count())->toBe(0);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('write and deploy token can create and queue a docker image application', function () {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'alpine',
            'instant_deploy' => true,
        ])->assertCreated();

    expect(ApplicationDeploymentQueue::count())->toBe(1);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

test('all other application create variants reject write-only instant deployment', function (string $path) {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson($path, ['instant_deploy' => true])
        ->assertForbidden();

    expect(ApplicationDeploymentQueue::count())->toBe(0);
})->with([
    '/api/v1/applications/public',
    '/api/v1/applications/private-github-app',
    '/api/v1/applications/private-deploy-key',
    '/api/v1/applications/dockerfile',
]);

test('write-only token cannot create and deploy a service', function () {
    $before = Service::count();

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  web:\n    image: nginx:alpine\n"),
            'instant_deploy' => true,
        ])->assertForbidden();

    expect(Service::count())->toBe($before);
});

test('write and deploy token can create and start a service', function () {
    $before = Service::count();

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson('/api/v1/services', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_compose_raw' => base64_encode("services:\n  web:\n    image: nginx:alpine\n"),
            'instant_deploy' => true,
        ])->assertCreated();

    expect(Service::count())->toBe($before + 1);
});

test('write-only token cannot update and deploy a service', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'original-service',
    ]);

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->patchJson("/api/v1/services/{$service->uuid}", [
            'name' => 'edited-service',
            'instant_deploy' => true,
        ])->assertForbidden();

    expect($service->fresh()->name)->toBe('original-service');
});

test('write and deploy token can update and start a service', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'original-service',
    ]);

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->patchJson("/api/v1/services/{$service->uuid}", [
            'name' => 'edited-service',
            'instant_deploy' => true,
        ])->assertOk();

    expect($service->fresh()->name)->toBe('edited-service');
});

test('write-only token cannot create and deploy a database', function () {
    $before = StandalonePostgresql::count();

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson('/api/v1/databases/postgresql', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'instant_deploy' => true,
        ])->assertForbidden();

    expect(StandalonePostgresql::count())->toBe($before);
});

test('write and deploy token can create and start a database', function () {
    $before = StandalonePostgresql::count();

    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write', 'deploy']))
        ->postJson('/api/v1/databases/postgresql', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'instant_deploy' => true,
        ])->assertCreated();

    expect(StandalonePostgresql::count())->toBe($before + 1);
});

test('all other database create variants reject write-only instant deployment', function (string $path) {
    $this->withHeaders(instantDeployHeaders($this->user, $this->team->id, ['write']))
        ->postJson($path, ['instant_deploy' => true])
        ->assertForbidden();
})->with([
    '/api/v1/databases/mysql',
    '/api/v1/databases/mariadb',
    '/api/v1/databases/mongodb',
    '/api/v1/databases/redis',
    '/api/v1/databases/clickhouse',
    '/api/v1/databases/dragonfly',
    '/api/v1/databases/keydb',
    '/api/v1/databases/sqlite',
]);
