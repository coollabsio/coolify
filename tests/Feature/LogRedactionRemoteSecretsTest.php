<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const DOPPLER_DOWNLOAD_URL = 'https://api.doppler.com/v3/configs/config/secrets/download*';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->resourceAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];
    $this->serverId = $server->id;
});

function makeRemoteSecretResource(string $type): Application|Service|StandalonePostgresql
{
    $attributes = test()->resourceAttributes;

    return match ($type) {
        'application' => Application::factory()->create($attributes),
        'service' => Service::factory()->create([...$attributes, 'server_id' => test()->serverId]),
        'database' => StandalonePostgresql::query()->create([...$attributes, 'name' => 'remote-secret-db', 'postgres_password' => 'stored-db-password']),
    };
}

function addRemoteSecretVariable(Application|Service|StandalonePostgresql $resource, string $key, string $value, bool $locked = false, bool $preview = false): void
{
    EnvironmentVariable::query()->create([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => $resource->getMorphClass(),
        'resourceable_id' => $resource->id,
        'is_preview' => $preview,
        'is_shown_once' => $locked,
    ]);
}

function linkDopplerSource(Application|Service|StandalonePostgresql $resource): void
{
    $token = IntegrationToken::factory()->create([
        'team_id' => test()->team->id,
        'provider' => 'doppler',
        'capabilities' => ['secrets'],
    ]);
    $resource->secretManagerLink()->create(['integration_token_id' => $token->id]);
}

it('hides resolved remote secrets of applications and services as locked values', function (string $type, bool $locked) {
    Http::fake([DOPPLER_DOWNLOAD_URL => Http::response(['API_TOKEN' => 'resolved-remote-api-token'])]);
    $resource = makeRemoteSecretResource($type);
    if ($type === 'application') {
        $resource->settings->update(['redact_all_env_values_in_logs' => false]);
    } else {
        $resource->update(['redact_all_env_values_in_logs' => false]);
    }
    linkDopplerSource($resource);
    addRemoteSecretVariable($resource, 'API_TOKEN', '{{vault.API_TOKEN}}', locked: $locked);

    $secrets = EnvironmentVariable::logRedactionSecretsFor($resource->fresh());

    expect($secrets['locked'])->toContain('resolved-remote-api-token')
        ->and(sanitizeLogsForExport('token resolved-remote-api-token', $secrets))->toBe('token '.REDACTED);
})->with(['application', 'service'])->with(['locked' => true, 'unlocked' => false]);

it('hides resolved remote secrets of application preview variables', function () {
    Http::fake([DOPPLER_DOWNLOAD_URL => Http::response(['PREVIEW_TOKEN' => 'resolved-preview-token'])]);
    $application = makeRemoteSecretResource('application');
    linkDopplerSource($application);
    addRemoteSecretVariable($application, 'PREVIEW_TOKEN', '{{vault.PREVIEW_TOKEN}}', preview: true);

    expect(EnvironmentVariable::logRedactionSecretsFor($application->fresh(), includePreview: true)['locked'])
        ->toContain('resolved-preview-token');
});

it('fetches the secret source once per call', function () {
    Http::fake([DOPPLER_DOWNLOAD_URL => Http::response(['FIRST' => 'first-remote-value', 'SECOND' => 'second-remote-value'])]);
    $application = makeRemoteSecretResource('application');
    linkDopplerSource($application);
    addRemoteSecretVariable($application, 'FIRST', '{{vault.FIRST}}');
    addRemoteSecretVariable($application, 'SECOND', '{{vault.SECOND}}', locked: true);

    $secrets = EnvironmentVariable::logRedactionSecretsFor($application->fresh());

    expect($secrets['locked'])->toContain('first-remote-value', 'second-remote-value');
    Http::assertSentCount(1);
});

it('does not contact a secret source for resources without remote references', function (string $type) {
    $resource = makeRemoteSecretResource($type);
    linkDopplerSource($resource);
    addRemoteSecretVariable($resource, 'PLAIN', 'plain-value', locked: true);

    expect(EnvironmentVariable::logRedactionSecretsFor($resource->fresh())['locked'])->toContain('plain-value');
    Http::assertNothingSent();
})->with(['application', 'service', 'database']);

it('keeps exporting logs when remote secrets cannot be resolved', function (string $type, string $failure) {
    Log::spy();
    $resource = makeRemoteSecretResource($type);
    if ($failure === 'source fails') {
        Http::fake([DOPPLER_DOWNLOAD_URL => Http::response(['message' => 'unavailable'], 500)]);
        linkDopplerSource($resource);
    } elseif ($failure === 'missing key') {
        Http::fake([DOPPLER_DOWNLOAD_URL => Http::response(['PRESENT' => 'present-remote-value'])]);
        linkDopplerSource($resource);
    }
    addRemoteSecretVariable($resource, 'LOCKED_PLAIN', 'locked-plain-value', locked: true);
    addRemoteSecretVariable($resource, 'BROKEN', '{{vault.PRESENT}}-{{vault.MISSING}}');
    addRemoteSecretVariable($resource, 'ALSO_BROKEN', '{{vault.MISSING}}');

    $secrets = EnvironmentVariable::logRedactionSecretsFor($resource->fresh());

    expect($secrets['locked'])->toContain('locked-plain-value', '{{vault.PRESENT}}-{{vault.MISSING}}', '{{vault.MISSING}}');
    if ($failure === 'missing key') {
        expect($secrets['locked'])->toContain('present-remote-value');
    }
    if ($type === 'database') {
        expect($secrets['locked'])->toContain('stored-db-password');
    }
    Http::assertSentCount($failure === 'no source' ? 0 : 1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'present-remote-value'));
})->with(['application', 'service', 'database'])->with(['source fails', 'missing key', 'no source']);

it('skips the secret source when the caller already holds the remote values', function () {
    $application = makeRemoteSecretResource('application');
    linkDopplerSource($application);
    addRemoteSecretVariable($application, 'API_TOKEN', '{{vault.API_TOKEN}}', locked: true);

    expect(EnvironmentVariable::logRedactionSecretsFor($application->fresh(), resolveRemoteSecrets: false)['locked'])
        ->toContain('{{vault.API_TOKEN}}');
    Http::assertNothingSent();
});
