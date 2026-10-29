<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    config(['app.maintenance.driver' => 'file']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $this->user->createToken('secret-manager-api-test', ['*'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->environment = $environment;
    $this->destination = $destination;
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function secretManagerApiHeaders(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

test('a secret manager integration token can be created through the api', function () {
    Http::fake(['https://api.doppler.com/v3/me' => Http::response([], 200)]);

    $response = $this->withHeaders(secretManagerApiHeaders($this->bearerToken))
        ->postJson('/api/v1/security/integration-tokens', [
            'provider' => 'doppler',
            'name' => 'Production secrets',
            'token' => 'dp.st.secret',
        ])
        ->assertCreated()
        ->assertJsonStructure(['uuid']);

    $token = IntegrationToken::query()->whereUuid($response->json('uuid'))->firstOrFail();

    expect($token->team_id)->toBe($this->team->id)
        ->and($token->capabilities)->toBe(['secrets']);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'api.integration_token.created',
        'resource_uuid' => $token->uuid,
    ]);
});

test('secret manager provider base urls only accept http and https', function (string $provider, array $metadata) {
    Http::fake();

    $this->withHeaders(secretManagerApiHeaders($this->bearerToken))
        ->postJson('/api/v1/security/integration-tokens', [
            'provider' => $provider,
            'name' => 'Invalid base URL',
            'token' => 'token',
            'metadata' => $metadata,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('metadata.base_url');

    Http::assertNothingSent();
})->with([
    'infisical' => ['infisical', ['base_url' => 'ftp://infisical.example.com', 'client_id' => 'client-1']],
    'vault' => ['vault', ['base_url' => 'ftp://vault.example.com']],
]);

test('an application can be configured to use a secret manager through the api', function () {
    $token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Production secrets',
        'token' => 'dp.sa.secret',
        'capabilities' => ['secrets'],
    ]);

    $this->withHeaders(secretManagerApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/secret-manager", [
            'integration_token_uuid' => $token->uuid,
            'settings' => [
                'project' => 'website',
                'config' => 'production',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('integration_token_uuid', $token->uuid)
        ->assertJsonPath('provider', 'doppler')
        ->assertJsonPath('settings.project', 'website');

    $link = $this->application->secretManagerLink()->firstOrFail();

    expect($link->integration_token_id)->toBe($token->id)
        ->and($link->settings)->toBe(['project' => 'website', 'config' => 'production']);

    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'api.application.secret_manager.source_updated',
        'resource_uuid' => $this->application->uuid,
    ]);
});

function secretManagerApiDopplerToken(object $test): IntegrationToken
{
    return IntegrationToken::query()->create([
        'team_id' => $test->team->id, 'provider' => 'doppler', 'name' => 'Doppler', 'token' => 'dp.st.secret-value', 'capabilities' => ['secrets'],
    ]);
}

function secretManagerApiResource(object $test, string $type): Application|Service|StandalonePostgresql
{
    return match ($type) {
        'applications' => $test->application,
        'services' => Service::factory()->create([
            'server_id' => $test->destination->server_id,
            'destination_id' => $test->destination->id,
            'destination_type' => $test->destination->getMorphClass(),
            'environment_id' => $test->environment->id,
        ]),
        'databases' => StandalonePostgresql::create([
            'uuid' => (string) Str::uuid(), 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'pg-secret',
            'postgres_db' => 'app', 'image' => 'postgres:17', 'environment_id' => $test->environment->id,
            'destination_id' => $test->destination->id, 'destination_type' => $test->destination->getMorphClass(),
        ]),
    };
}

test('a secret manager source is linked, read, and removed for each resource type', function (string $type) {
    $token = secretManagerApiDopplerToken($this);
    $resource = secretManagerApiResource($this, $type);
    $headers = secretManagerApiHeaders($this->bearerToken);

    $this->withHeaders($headers)->patchJson("/api/v1/{$type}/{$resource->uuid}/secret-manager", ['integration_token_uuid' => $token->uuid])->assertOk();
    $read = $this->withHeaders($headers)->getJson("/api/v1/{$type}/{$resource->uuid}/secret-manager")
        ->assertOk()
        ->assertJson(['linked' => true, 'integration_token_uuid' => $token->uuid, 'provider' => 'doppler', 'available_tokens' => [['uuid' => $token->uuid]]]);
    expect($read->getContent())->not->toContain('secret-value');

    $this->withHeaders($headers)->deleteJson("/api/v1/{$type}/{$resource->uuid}/secret-manager")->assertOk();
    expect($resource->secretManagerLink()->exists())->toBeFalse();
})->with(['applications', 'services', 'databases']);

test('remote key names are listed and imported as references, and values are never returned', function () {
    $token = secretManagerApiDopplerToken($this);
    $this->application->secretManagerLink()->create(['integration_token_id' => $token->id]);
    $this->application->environment_variables()->create(['key' => 'EXISTING', 'value' => 'x']);
    Http::fake(['https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
        'API_KEY' => 'remote-value-1', 'EXISTING' => 'remote-value-2', 'db-password' => 'remote-value-3',
    ])]);
    $headers = secretManagerApiHeaders($this->bearerToken);
    $base = "/api/v1/applications/{$this->application->uuid}/secret-manager";

    $keys = $this->withHeaders($headers)->getJson("{$base}/keys")->assertOk()->assertExactJson(['keys' => ['API_KEY', 'EXISTING', 'db-password']]);
    $this->withHeaders($headers)->postJson("{$base}/import")->assertOk()->assertExactJson(['imported' => ['API_KEY'], 'skipped' => ['db-password']]);

    expect($keys->getContent())->not->toContain('remote-value')
        ->and($this->application->environment_variables()->where('key', 'API_KEY')->value('value'))->toBe('{{vault.API_KEY}}');
});

test('a reference is added only for a remote key with a valid name that has no variable yet', function (string $key, ?string $error) {
    $token = secretManagerApiDopplerToken($this);
    $this->application->secretManagerLink()->create(['integration_token_id' => $token->id]);
    $this->application->environment_variables()->create(['key' => 'EXISTING', 'value' => 'x']);
    Http::fake(['https://api.doppler.com/*' => Http::response(['API_KEY' => 'v', 'EXISTING' => 'v', 'db-password' => 'v'])]);

    $response = $this->withHeaders(secretManagerApiHeaders($this->bearerToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/secret-manager/references", ['key' => $key]);

    if ($error === null) {
        $response->assertCreated();
        expect($this->application->environment_variables()->where('key', $key)->value('value'))->toBe("{{vault.{$key}}}");
    } else {
        $response->assertUnprocessable()->assertJsonPath('errors.key.0', $error);
    }
})->with([
    'remote key' => ['API_KEY', null],
    'unknown key' => ['MISSING', 'MISSING is not a key of the secret manager source.'],
    'invalid name' => ['db-password', 'db-password is not a valid variable name.'],
    'existing variable' => ['EXISTING', 'A variable with the key EXISTING already exists.'],
]);

test('a member with read access can read the source but not list keys or remove it', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    Http::fake();
    $base = "/api/v1/applications/{$this->application->uuid}/secret-manager";

    $readOnly = $member->createToken('member-read', ['read'])->plainTextToken;
    $this->withHeaders(secretManagerApiHeaders($readOnly))->getJson($base)->assertOk();
    $this->withHeaders(secretManagerApiHeaders($readOnly))->getJson("{$base}/keys")->assertForbidden();
    $this->withHeaders(secretManagerApiHeaders($readOnly))->deleteJson($base)->assertForbidden();
    Http::assertNothingSent();
});

test('a token or resource of another team cannot be used', function () {
    $otherTeam = Team::factory()->create();
    $foreignToken = IntegrationToken::query()->create([
        'team_id' => $otherTeam->id, 'provider' => 'doppler', 'name' => 'Foreign', 'token' => 'dp.st.x', 'capabilities' => ['secrets'],
    ]);
    $otherTeam->members()->attach($this->user, ['role' => 'owner']);
    $otherBearer = $this->user->createToken('other', ['*'], teamId: $otherTeam->id)->plainTextToken;

    $this->withHeaders(secretManagerApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}/secret-manager", ['integration_token_uuid' => $foreignToken->uuid])
        ->assertNotFound();
    app('auth')->forgetGuards();
    $this->withHeaders(secretManagerApiHeaders($otherBearer))
        ->getJson("/api/v1/applications/{$this->application->uuid}/secret-manager")
        ->assertNotFound();

    expect($this->application->secretManagerLink()->exists())->toBeFalse();
});
