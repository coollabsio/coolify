<?php

use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->tokens()->create(['name' => 'activities', 'token' => hash('sha256', 'secret'), 'abilities' => ['deploy', 'read'], 'team_id' => $this->team->id]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token->id.'|secret'];
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(['server_id' => $this->server->id, 'network' => 'coolify'], ['uuid' => (string) Str::uuid(), 'name' => 'docker']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->service = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services:\n  app:\n    image: nginx\n"]);
});

function mockStartService(Activity $activity, ?callable $args = null): void
{
    $action = Mockery::mock(StartService::class);
    $expectation = $action->shouldReceive('handle')->once()->andReturn($activity);
    if ($args) {
        $expectation->withArgs($args);
    }
    app()->instance(StartService::class, $action);
}

test('restart returns the activity id of the started process', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['status' => 'queued']]);
    mockStartService($activity, fn ($service, $pullLatestImages, $stopBeforeStart) => $service->is($this->service) && $pullLatestImages === true && $stopBeforeStart === true);

    $this->withHeaders($this->headers)->postJson("/api/v1/services/{$this->service->uuid}/restart?latest=true")
        ->assertOk()
        ->assertJson(['message' => 'Service restarting request queued.', 'activity_id' => $activity->id]);
});

test('start returns the activity id of the started process', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['status' => 'queued']]);
    mockStartService($activity, fn ($service) => $service->is($this->service));

    $this->withHeaders($this->headers)->postJson("/api/v1/services/{$this->service->uuid}/start")
        ->assertOk()
        ->assertJson(['message' => 'Service starting request queued.', 'activity_id' => $activity->id]);
});

test('shows a team and service scoped activity', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => json_encode([['order' => 2, 'output' => "Starting service.\n", 'type' => 'stdout'], ['order' => 1, 'output' => "Pulling images.\n", 'type' => 'stdout']]), 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $this->service->uuid, 'status' => 'finished', 'exitCode' => 0, 'command' => 'docker compose pull']]);

    $this->withHeaders($this->headers)->getJson("/api/v1/services/{$this->service->uuid}/activities/{$activity->id}")
        ->assertOk()
        ->assertJson(['id' => $activity->id, 'status' => 'finished', 'exit_code' => 0, 'output' => "Pulling images.\nStarting service.\n"])
        ->assertJsonPath('finished_at', fn ($value) => $value !== null)
        ->assertJsonStructure(['id', 'status', 'exit_code', 'output', 'created_at', 'updated_at', 'finished_at'])
        ->assertJsonMissingPath('command');
});

test('reports a running activity without a finished timestamp', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $this->service->uuid, 'status' => 'in_progress']]);

    $this->withHeaders($this->headers)->getJson("/api/v1/services/{$this->service->uuid}/activities/{$activity->id}")
        ->assertOk()
        ->assertJson(['id' => $activity->id, 'status' => 'in_progress', 'exit_code' => null, 'output' => '', 'finished_at' => null]);
});

test('returns not found for an activity of another team', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['team_id' => $this->team->id + 1, 'type_uuid' => $this->service->uuid, 'status' => 'finished']]);

    $this->withHeaders($this->headers)->getJson("/api/v1/services/{$this->service->uuid}/activities/{$activity->id}")->assertNotFound();
});

test('returns not found for an activity of another service', function () {
    $otherService = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services: {}\n"]);
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $otherService->uuid, 'status' => 'finished']]);

    $this->withHeaders($this->headers)->getJson("/api/v1/services/{$this->service->uuid}/activities/{$activity->id}")->assertNotFound();
});

test('returns not found for a service of another team', function () {
    $foreignTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $foreignTeam->id]);
    $foreignEnvironment = Environment::factory()->create(['project_id' => $foreignProject->id]);
    $foreignService = Service::factory()->create(['environment_id' => $foreignEnvironment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services: {}\n"]);
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['team_id' => $foreignTeam->id, 'type_uuid' => $foreignService->uuid, 'status' => 'finished']]);

    $this->withHeaders($this->headers)->getJson("/api/v1/services/{$foreignService->uuid}/activities/{$activity->id}")->assertNotFound();
});

test('requires authentication to show an activity', function () {
    $this->getJson("/api/v1/services/{$this->service->uuid}/activities/1")->assertUnauthorized();
});

test('requires the read ability to show an activity', function () {
    $deployOnly = $this->user->createToken('deploy-only', ['deploy']);
    $activity = Activity::create(['log_name' => 'default', 'description' => '[]', 'properties' => ['team_id' => $this->team->id, 'type_uuid' => $this->service->uuid, 'status' => 'finished']]);

    $this->withToken($deployOnly->plainTextToken)->getJson("/api/v1/services/{$this->service->uuid}/activities/{$activity->id}")->assertForbidden();
});
