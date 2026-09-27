<?php

use App\Http\Middleware\ApiAbility;
use App\Http\Middleware\EnsureTokenBelongsToCurrentTeamMember;
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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->token = $this->user->tokens()->create(['name' => 'imports', 'token' => hash('sha256', 'secret'), 'abilities' => ['deploy', 'read'], 'team_id' => $this->team->id]);
    $this->headers = ['Authorization' => 'Bearer '.$this->token->id.'|secret'];
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(['server_id' => $this->server->id, 'network' => 'coolify'], ['uuid' => (string) Str::uuid(), 'name' => 'docker']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('validates service database imports and binds database to service', function () {
    $service = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services:\n  postgres:\n    image: postgres:17\n"]);
    $database = ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'name' => 'postgres', 'service_id' => $service->id, 'image' => 'postgres:17']);

    $url = "/api/v1/services/{$service->uuid}/databases/{$database->uuid}/imports";
    $this->withHeaders($this->headers)->postJson($url, ['source' => 's3', 'upload_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors(['upload_id', 's3_storage_uuid', 'path']);

    $otherService = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services: {}\n"]);
    $this->withHeaders($this->headers)->postJson("/api/v1/services/{$otherService->uuid}/databases/{$database->uuid}/imports", ['source' => 'server', 'path' => '/tmp/a.sql'])->assertNotFound();
});

test('returns invalid token when the access token team is not a member team', function (string $method, string $suffix) {
    $this->withoutMiddleware([
        EnsureTokenBelongsToCurrentTeamMember::class,
        ApiAbility::class,
    ]);

    $service = Service::factory()->create(['environment_id' => $this->environment->id, 'server_id' => $this->server->id, 'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(), 'docker_compose_raw' => "services:\n  postgres:\n    image: postgres:17\n"]);
    $database = ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'name' => 'postgres', 'service_id' => $service->id, 'image' => 'postgres:17']);
    $foreignTeam = Team::factory()->create();
    $plainTextToken = 'no-team';
    $token = $this->user->tokens()->create([
        'name' => 'imports-foreign-team',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['deploy', 'read'],
        'team_id' => $foreignTeam->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->id.'|'.$plainTextToken])
        ->{$method}("/api/v1/services/{$service->uuid}/databases/{$database->uuid}/{$suffix}")
        ->assertBadRequest()
        ->assertJson([
            'message' => 'Invalid token.',
            'docs' => 'https://coolify.io/docs/api-reference/authorization',
        ]);
})->with([
    'upload' => ['postJson', 'imports/uploads'],
    'create' => ['postJson', 'imports'],
    'show' => ['getJson', 'imports/1'],
]);
