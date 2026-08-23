<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});
