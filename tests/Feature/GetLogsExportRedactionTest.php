<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const GET_LOGS_EXPORT_CANARY = 'coolify-canary-exported-env-value';

beforeEach(function () {
    Server::flushIdentityMap();
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();
    $this->server = Server::with('settings')->findOrFail($this->server->id);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destinationAttributes = ['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $this->application = Application::factory()->create(['environment_id' => $environment->id, ...$destinationAttributes]);
    $this->service = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $this->server->id, ...$destinationAttributes]);
    $this->serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $this->service->id]);

    foreach ([$this->application, $this->service] as $resource) {
        EnvironmentVariable::query()->create([
            'key' => 'EXPORT_CANARY',
            'value' => GET_LOGS_EXPORT_CANARY,
            'resourceable_type' => $resource->getMorphClass(),
            'resourceable_id' => $resource->id,
            'is_preview' => false,
        ]);
    }

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Process::fake(['*' => Process::result(output: '2026-09-28T10:00:01Z booted with '.GET_LOGS_EXPORT_CANARY)]);
});

it('redacts the resource environment values when copying or downloading logs', function (string $resource, string $action) {
    $parameters = $resource === 'application'
        ? ['resource' => $this->application]
        : ['resource' => $this->service, 'servicesubtype' => $this->serviceApplication];

    Livewire::test(GetLogs::class, ['server' => $this->server, 'container' => 'test-container', ...$parameters])
        ->call($action)
        ->assertReturned('2026-09-28T10:00:01Z booted with '.REDACTED);
})->with(['application', 'service'])->with(['copyLogs', 'downloadAllLogs']);

it('redacts stored and effective database passwords in exported logs', function (string $path, bool $remote) {
    $database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-database',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'postgres_password' => 'xy',
        'status' => 'running:healthy',
    ]);
    EnvironmentVariable::query()->create([
        'key' => 'POSTGRES_PASSWORD',
        'value' => $remote ? '{{vault.DB_PASSWORD}}' : 'effective-database-canary',
        'resourceable_type' => $database->getMorphClass(),
        'resourceable_id' => $database->id,
        'is_preview' => false,
    ]);
    if ($remote) {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response(['DB_PASSWORD' => 'effective-database-canary']),
        ]);
        $token = IntegrationToken::factory()->create([
            'team_id' => $database->team()->id,
            'provider' => 'doppler',
            'capabilities' => ['secrets'],
        ]);
        $database->secretManagerLink()->create(['integration_token_id' => $token->id]);
    }
    Process::fake(fn ($process) => match (true) {
        str_contains($process->command, 'docker ps') => Process::result(output: json_encode(['ID' => 'abc123', 'Names' => $database->uuid, 'Labels' => '', 'State' => 'running'])),
        str_contains($process->command, 'docker inspect') => Process::result(output: json_encode(['State' => ['Status' => 'running']])),
        str_contains($process->command, 'docker logs') => Process::result(output: 'booted xy effective-database-canary'),
        default => Process::result(output: ''),
    });

    if ($path === 'api') {
        InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
        $user = auth()->user();
        $accessToken = $user->createToken('database-logs', ['read', 'read:sensitive']);
        $accessToken->accessToken->update(['team_id' => $database->team()->id]);
        $token = $accessToken->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($token)->getJson("/api/v1/databases/{$database->uuid}/logs")
            ->assertOk()->assertJsonPath('logs', 'booted '.REDACTED.' '.REDACTED);
    } else {
        Livewire::test(GetLogs::class, ['server' => $this->server, 'container' => $database->uuid, 'resource' => $database])
            ->call($path)->assertReturned('booted '.REDACTED.' '.REDACTED);
    }
})->with(['copyLogs', 'downloadAllLogs', 'api'])->with([false, true]);

it('still exports database logs when its remote secret source fails', function (string $path) {
    $database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-failing-source',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'postgres_password' => 'xy',
        'status' => 'running:healthy',
    ]);
    EnvironmentVariable::query()->create([
        'key' => 'POSTGRES_PASSWORD',
        'value' => '{{vault.DB_PASSWORD}}',
        'resourceable_type' => $database->getMorphClass(),
        'resourceable_id' => $database->id,
        'is_preview' => false,
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response(['message' => 'down'], 500)]);
    $token = IntegrationToken::factory()->create([
        'team_id' => $database->team()->id,
        'provider' => 'doppler',
        'capabilities' => ['secrets'],
    ]);
    $database->secretManagerLink()->create(['integration_token_id' => $token->id]);
    Process::fake(fn ($process) => match (true) {
        str_contains($process->command, 'docker ps') => Process::result(output: json_encode(['ID' => 'abc123', 'Names' => $database->uuid, 'Labels' => '', 'State' => 'running'])),
        str_contains($process->command, 'docker inspect') => Process::result(output: json_encode(['State' => ['Status' => 'running']])),
        str_contains($process->command, 'docker logs') => Process::result(output: 'booted xy {{vault.DB_PASSWORD}}'),
        default => Process::result(output: ''),
    });

    if ($path === 'api') {
        InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
        $accessToken = auth()->user()->createToken('database-logs', ['read', 'read:sensitive']);
        $accessToken->accessToken->update(['team_id' => $database->team()->id]);
        auth()->forgetGuards();
        $this->withToken($accessToken->plainTextToken)->getJson("/api/v1/databases/{$database->uuid}/logs")
            ->assertOk()->assertJsonPath('logs', 'booted '.REDACTED.' '.REDACTED);
    } else {
        Livewire::test(GetLogs::class, ['server' => $this->server, 'container' => $database->uuid, 'resource' => $database])
            ->call($path)->assertReturned('booted '.REDACTED.' '.REDACTED);
    }
})->with(['copyLogs', 'downloadAllLogs', 'api']);

it('redacts database environment values with the same rules as other resources', function (bool $redactAll, string $expected) {
    $database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-rules-database',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'postgres_password' => 'xy',
    ]);
    $database->update(['redact_all_env_values_in_logs' => $redactAll]);
    foreach ([['TZ', 'UTC', false], ['APP_MODE', 'production-database-mode', false], ['LOCKED', 'q7', true]] as [$key, $value, $locked]) {
        EnvironmentVariable::query()->create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => $database->getMorphClass(),
            'resourceable_id' => $database->id,
            'is_preview' => false,
            'is_shown_once' => $locked,
        ]);
    }

    $secrets = EnvironmentVariable::logRedactionSecretsFor($database->fresh());

    expect(sanitizeLogsForExport('tz UTC mode production-database-mode locked q7 password xy', $secrets))->toBe($expected);
})->with([
    'hide all values' => [true, 'tz UTC mode '.REDACTED.' locked '.REDACTED.' password '.REDACTED],
    'hide locked values only' => [false, 'tz UTC mode production-database-mode locked '.REDACTED.' password '.REDACTED],
]);

it('redacts stored database credentials even without environment variables', function (string $model, array $attributes) {
    $database = new $model($attributes);
    if ($database instanceof StandaloneMongodb) {
        $database->mongo_initdb_root_password = encrypt($attributes['mongo_initdb_root_password']);
    }
    $database->setRelation('runtime_environment_variables', collect());

    $secrets = EnvironmentVariable::logRedactionSecretsFor($database);

    expect(sanitizeLogsForExport('booted '.implode(' ', array_values($attributes)), $secrets))
        ->toBe('booted '.implode(' ', array_fill(0, count($attributes), REDACTED)));
})->with([
    'postgres' => [StandalonePostgresql::class, ['postgres_password' => 'xy']],
    'mysql' => [StandaloneMysql::class, ['mysql_password' => 'xy', 'mysql_root_password' => 'zw']],
    'mariadb' => [StandaloneMariadb::class, ['mariadb_password' => 'xy', 'mariadb_root_password' => 'zw']],
    'mongodb' => [StandaloneMongodb::class, ['mongo_initdb_root_password' => 'xy']],
    'clickhouse' => [StandaloneClickhouse::class, ['clickhouse_admin_password' => 'xy']],
    'keydb' => [StandaloneKeydb::class, ['keydb_password' => 'xy']],
    'dragonfly' => [StandaloneDragonfly::class, ['dragonfly_password' => 'xy']],
]);
