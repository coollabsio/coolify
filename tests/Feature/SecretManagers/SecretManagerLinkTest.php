<?php

use App\Actions\Database\StartRedis;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Security\IntegrationTokens;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Traits\HasSecretManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function createSecretManagerLink(string $provider, array $settings = [], array $metadata = []): SecretManagerLink
{
    $token = IntegrationToken::query()->create([
        'team_id' => test()->team->id,
        'provider' => $provider,
        'name' => ucfirst($provider).' token',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
        'metadata' => $metadata ?: null,
    ]);

    return test()->application->secretManagerLink()->create([
        'integration_token_id' => $token->id,
        'settings' => $settings ?: null,
    ]);
}

function makeDeploymentJobForSecrets(): ApplicationDeploymentJob
{
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    $queue = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => 'secrets-test-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => test()->application->destination->server->id,
        'destination_id' => test()->application->destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);

    $properties = [
        'application' => test()->application,
        'application_deployment_queue' => $queue,
        'mainServer' => test()->application->destination->server,
        'pull_request_id' => 0,
    ];

    foreach ($properties as $property => $value) {
        $reflection = new ReflectionProperty($job, $property);
        $reflection->setValue($job, $value);
    }

    return $job;
}

function resolveEnvOnJob(ApplicationDeploymentJob $job, $env): ?string
{
    return (new ReflectionMethod($job, 'resolve_environment_variable'))->invoke($job, $env);
}

function deploymentHasRemoteBuildtimeReferences(ApplicationDeploymentJob $job): bool
{
    return (new ReflectionMethod($job, 'has_remote_buildtime_secret_references'))->invoke($job);
}

test('remote build-time secret references prevent same-commit image reuse', function (string $reference) {
    $this->application->environment_variables()->create([
        'key' => 'BUILD_SECRET',
        'value' => $reference,
        'is_buildtime' => true,
    ]);

    expect(deploymentHasRemoteBuildtimeReferences(makeDeploymentJobForSecrets()))->toBeTrue();
})->with([
    'provider-neutral reference' => '{{vault.BUILD_SECRET}}',
]);

test('runtime-only remote secret references still allow same-commit image reuse', function () {
    $this->application->environment_variables()->create([
        'key' => 'RUNTIME_SECRET',
        'value' => '{{vault.RUNTIME_SECRET}}',
        'is_buildtime' => false,
    ]);

    expect(deploymentHasRemoteBuildtimeReferences(makeDeploymentJobForSecrets()))->toBeFalse();
});

test('a doppler link fetches secrets with the stored token', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'DB_PASSWORD' => 's3cret',
        ]),
    ]);

    $link = createSecretManagerLink('doppler', ['project' => 'proj', 'config' => 'prd']);

    expect($link->fetchSecrets())->toBe(['DB_PASSWORD' => 's3cret']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer the-secret-token')
        && str_contains($request->url(), 'project=proj'));
});

test('a vault link uses the base url and namespace from the token metadata', function () {
    Http::fake([
        'https://example.com:8200/v1/kv/data/apps/web' => Http::response([
            'data' => ['data' => ['KEY' => 'value']],
        ]),
    ]);

    $link = createSecretManagerLink('vault',
        ['mount' => 'kv', 'path' => 'apps/web'],
        ['base_url' => 'https://example.com:8200', 'namespace' => 'team-a'],
    );

    expect($link->fetchSecrets())->toBe(['KEY' => 'value']);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Vault-Namespace', 'team-a'));
});

test('services resolve environment variables from their configured secret manager', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'API_KEY' => 'remote-service-value',
        ]),
    ]);

    $service = Service::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Service secrets',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
    $service->secretManagerLink()->create(['integration_token_id' => $token->id]);
    $environmentVariable = $service->environment_variables()->create([
        'key' => 'API_KEY',
        'value' => '{{vault.API_KEY}}',
    ]);

    expect($service->resolveSecretManagerEnvironmentVariable($environmentVariable))->toBe('remote-service-value');
});

test('redis remote credentials stay deployment-local and use raw values in the start command', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'REDIS_PASSWORD' => 'p4$$word',
            'REDIS_USERNAME' => 'remote-user',
        ]),
    ]);

    $redis = StandaloneRedis::forceCreate([
        'uuid' => 'redis-secret-test',
        'name' => 'Redis secret test',
        'image' => 'redis:7-alpine',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Redis secrets',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
    $redis->secretManagerLink()->create(['integration_token_id' => $token->id]);
    $sharedPassword = SharedEnvironmentVariable::query()->create([
        'key' => 'REDIS_PASSWORD',
        'value' => '{{vault.REDIS_PASSWORD}}',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);
    $password = $redis->runtime_environment_variables()->create([
        'key' => 'REDIS_PASSWORD',
        'value' => '{{team.REDIS_PASSWORD}}',
    ]);
    $username = $redis->runtime_environment_variables()->create([
        'key' => 'REDIS_USERNAME',
        'value' => '{{vault.REDIS_USERNAME}}',
    ]);

    $action = new StartRedis;
    $action->database = $redis;
    $environmentVariables = (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);
    $startCommand = (new ReflectionMethod($action, 'buildStartCommand'))->invoke($action);

    expect($password->fresh()->value)->toBe('{{team.REDIS_PASSWORD}}')
        ->and($sharedPassword->fresh()->value)->toBe('{{vault.REDIS_PASSWORD}}')
        ->and($username->fresh()->value)->toBe('{{vault.REDIS_USERNAME}}')
        ->and($environmentVariables)->toContain('REDIS_PASSWORD=p4$$word')
        ->and($environmentVariables)->toContain('REDIS_USERNAME=remote-user')
        ->and($startCommand)->toContain('--requirepass p4$$word');
});

test('all deployable environment-variable resources support secret managers', function (string $resourceClass) {
    expect(class_uses_recursive($resourceClass))->toContain(HasSecretManager::class);
})->with([
    Application::class,
    Service::class,
    StandalonePostgresql::class,
    StandaloneMysql::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneRedis::class,
    StandaloneKeydb::class,
    StandaloneDragonfly::class,
    StandaloneClickhouse::class,
]);

test('an application has at most one secret manager source', function () {
    createSecretManagerLink('doppler');

    $secondToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vault',
        'name' => 'Vault token',
        'token' => 'other-token',
        'capabilities' => ['secrets'],
        'metadata' => ['base_url' => 'https://vault.internal:8200'],
    ]);

    expect(fn () => $this->application->secretManagerLink()->create([
        'integration_token_id' => $secondToken->id,
    ]))->toThrow(QueryException::class);
});

test('a secret reference is substituted at deploy time and formatted as a dotenv literal', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'DB_PASSWORD' => 'p4$$word',
        ]),
    ]);

    createSecretManagerLink('doppler');

    $env = $this->application->environment_variables()->create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://app:{{vault.DB_PASSWORD}}@db:5432/app',
    ]);

    $job = makeDeploymentJobForSecrets();

    expect(resolveEnvOnJob($job, $env))->toBe("'postgres://app:p4\$\$word@db:5432/app'");
});

test('provider alias references resolve against the single source', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'API_KEY' => 'abc',
        ]),
    ]);

    createSecretManagerLink('doppler');

    $env = $this->application->environment_variables()->create([
        'key' => 'API_KEY',
        'value' => '{{vault.API_KEY}}',
    ]);

    expect(resolveEnvOnJob(makeDeploymentJobForSecrets(), $env))->toBe("'abc'");
});

test('the fetch happens once per deployment even with many references', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'A' => '1',
            'B' => '2',
        ]),
    ]);

    createSecretManagerLink('doppler');

    $first = $this->application->environment_variables()->create(['key' => 'A', 'value' => '{{vault.A}}']);
    $second = $this->application->environment_variables()->create(['key' => 'B', 'value' => '{{vault.B}}']);

    $job = makeDeploymentJobForSecrets();
    resolveEnvOnJob($job, $first);
    resolveEnvOnJob($job, $second);

    Http::assertSentCount(1);
});

test('variables without references never contact the secret manager', function () {
    Http::fake();

    createSecretManagerLink('doppler');

    $env = $this->application->environment_variables()->create([
        'key' => 'PLAIN',
        'value' => 'plain-value',
    ]);

    expect(resolveEnvOnJob(makeDeploymentJobForSecrets(), $env))->toBe('plain-value');

    Http::assertNothingSent();
});

test('a null environment variable value remains null', function () {
    $env = $this->application->environment_variables()->create([
        'key' => 'EMPTY',
        'value' => null,
    ]);

    expect($this->application->resolveSecretManagerEnvironmentVariable($env))->toBeNull();
});

test('a missing secret key fails the deployment and names the variable', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'OTHER' => 'value',
        ]),
    ]);

    createSecretManagerLink('doppler');

    $env = $this->application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => '{{vault.GONE_KEY}}',
    ]);

    expect(fn () => resolveEnvOnJob(makeDeploymentJobForSecrets(), $env))
        ->toThrow(DeploymentException::class, 'Missing secret keys: GONE_KEY (referenced by DB_PASSWORD).');
});

test('a reference without a configured source fails the deployment', function () {
    Http::fake();

    $env = $this->application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => '{{vault.DB_PASSWORD}}',
    ]);

    expect(fn () => resolveEnvOnJob(makeDeploymentJobForSecrets(), $env))
        ->toThrow(DeploymentException::class, 'no secret manager source is configured');

    Http::assertNothingSent();
});

test('a fetch failure stops the deployment with a clear error', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'messages' => ['Invalid Auth token'],
        ], 401),
    ]);

    createSecretManagerLink('doppler');

    $env = $this->application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => '{{vault.DB_PASSWORD}}',
    ]);

    expect(fn () => resolveEnvOnJob(makeDeploymentJobForSecrets(), $env))
        ->toThrow(DeploymentException::class, 'Could not fetch secrets from Doppler.');
});

test('import creates reference variables for missing keys only', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'EXISTING' => 'value-a',
            'NEW_KEY' => 'value-b',
        ]),
    ]);

    createSecretManagerLink('doppler');
    $this->application->environment_variables()->create(['key' => 'EXISTING', 'value' => 'local']);

    $imported = $this->application->secretManagerLink->importMissingReferences();

    expect($imported)->toBe(['NEW_KEY']);

    $created = $this->application->environment_variables()->where('key', 'NEW_KEY')->firstOrFail();
    expect($created->value)->toBe('{{vault.NEW_KEY}}')
        ->and($this->application->environment_variables()->where('key', 'EXISTING')->firstOrFail()->value)->toBe('local');
});

test('secret references are not marked as shared variables', function () {
    $secretRef = $this->application->environment_variables()->create([
        'key' => 'A',
        'value' => '{{vault.A}}',
    ]);
    $sharedRef = $this->application->environment_variables()->create([
        'key' => 'B',
        'value' => '{{team.B}}',
    ]);

    expect($secretRef->refresh()->is_shared)->toBeFalse()
        ->and($sharedRef->refresh()->is_shared)->toBeTrue();
});

test('remote secret values are formatted as dotenv literals', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $format = fn (string $value) => (new ReflectionMethod($job, 'format_remote_secret_value'))->invoke($job, $value);

    expect($format('simple'))->toBe("'simple'")
        ->and($format('with $dollar and spaces'))->toBe("'with \$dollar and spaces'")
        ->and($format("it's quoted"))->toBe('"it\'s quoted"')
        ->and($format('{"json": true}'))->toBe('\'{"json": true}\'');
});

test('deleting an integration token is blocked while links exist', function () {
    $link = createSecretManagerLink('doppler');

    Livewire\Livewire::test(IntegrationTokens::class)
        ->call('deleteToken', $link->integration_token_id)
        ->assertDispatched('error');

    expect(IntegrationToken::query()->whereKey($link->integration_token_id)->exists())->toBeTrue();

    $link->delete();

    Livewire\Livewire::test(IntegrationTokens::class)
        ->call('deleteToken', $link->integration_token_id)
        ->assertDispatched('success');

    expect(IntegrationToken::query()->whereKey($link->integration_token_id)->exists())->toBeFalse();
});
