<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\RestartServiceApplication;
use App\Actions\Service\StopServiceApplication;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->bearerToken = createBearerTokenForServiceDatabasesApiTest($this->user, $this->team);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function createBearerTokenForServiceDatabasesApiTest(User $user, Team $team, array $abilities = ['*']): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function createServiceWithDatabaseForApiTest(object $context): object
{
    $service = Service::factory()->create([
        'environment_id' => $context->environment->id,
        'server_id' => $context->server->id,
        'destination_id' => $context->destination->id,
        'destination_type' => $context->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  postgres:\n    image: postgres:17-alpine\n",
    ]);

    $serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'service_id' => $service->id,
        'image' => 'postgres:17-alpine',
        'is_public' => false,
    ]);

    return (object) ['service' => $service, 'serviceDatabase' => $serviceDatabase];
}

test('lists and gets databases belonging to a service', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $headers = ['Authorization' => 'Bearer '.$this->bearerToken];

    $this->withHeaders($headers)
        ->getJson("/api/v1/services/{$context->service->uuid}/databases")
        ->assertOk()
        ->assertJsonFragment(['uuid' => $context->serviceDatabase->uuid]);

    $this->withHeaders($headers)
        ->getJson("/api/v1/services/{$context->service->uuid}/databases/{$context->serviceDatabase->uuid}")
        ->assertOk()
        ->assertJsonFragment(['name' => 'postgres']);
});

test('does not return a database from another service', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $otherContext = createServiceWithDatabaseForApiTest($this);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->getJson("/api/v1/services/{$context->service->uuid}/databases/{$otherContext->serviceDatabase->uuid}")
        ->assertNotFound()
        ->assertJsonFragment(['message' => 'Service database not found.']);
});

test('updates supported service database fields and rejects unknown fields', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $url = "/api/v1/services/{$context->service->uuid}/databases/{$context->serviceDatabase->uuid}";
    $headers = ['Authorization' => 'Bearer '.$this->bearerToken];

    $this->withHeaders($headers)->patchJson($url, [
        'human_name' => 'Primary database',
        'description' => 'Stores production data',
        'public_port' => 5432,
        'public_port_timeout' => 1800,
        'exclude_from_status' => true,
    ])->assertOk()->assertJsonFragment([
        'human_name' => 'Primary database',
        'public_port' => 5432,
    ]);

    $context->serviceDatabase->refresh();
    expect($context->serviceDatabase->human_name)->toBe('Primary database')
        ->and($context->serviceDatabase->public_port_timeout)->toBe(1800)
        ->and($context->serviceDatabase->exclude_from_status)->toBeTrue();

    $this->withHeaders($headers)
        ->patchJson($url, ['unsupported' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unsupported.0', 'This field is not allowed.');
});

test('queues lifecycle actions for a service database', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $baseUrl = "/api/v1/services/{$context->service->uuid}/databases/{$context->serviceDatabase->uuid}";
    $headers = ['Authorization' => 'Bearer '.$this->bearerToken];

    $this->withHeaders($headers)->postJson("{$baseUrl}/start?latest=1&force=1")
        ->assertOk()
        ->assertJsonFragment(['message' => 'Service database deploy request queued.']);
    DeployServiceApplication::assertPushed();

    $this->withHeaders($headers)->postJson("{$baseUrl}/restart")
        ->assertOk()
        ->assertJsonFragment(['message' => 'Service database restart request queued.']);
    RestartServiceApplication::assertPushed();

    $this->withHeaders($headers)->postJson("{$baseUrl}/stop")
        ->assertOk()
        ->assertJsonFragment(['message' => 'Service database stop request queued.']);
    StopServiceApplication::assertPushed();
});

test('requires deploy ability for service database lifecycle actions', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $writeToken = createBearerTokenForServiceDatabasesApiTest($this->user, $this->team, ['write']);

    $this->withHeaders(['Authorization' => 'Bearer '.$writeToken])
        ->postJson("/api/v1/services/{$context->service->uuid}/databases/{$context->serviceDatabase->uuid}/restart")
        ->assertForbidden();
});

test('returns a clear error when logs cannot be read from the server', function () {
    $context = createServiceWithDatabaseForApiTest($this);
    $this->server->settings->update(['is_reachable' => false]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->getJson("/api/v1/services/{$context->service->uuid}/databases/{$context->serviceDatabase->uuid}/logs")
        ->assertBadRequest()
        ->assertJsonFragment(['message' => 'Server is not functional.']);
});
