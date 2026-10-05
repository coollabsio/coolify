<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Exceptions\RemoteSecretException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\DatabaseStartJob;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const REMOTE_SECRET_LIFECYCLE_DOPPLER_URL = 'https://api.doppler.com/*';

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server->update(['private_key_id' => $privateKey->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Doppler',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
});

function remoteSecretLifecycleService(): Service
{
    $service = Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose' => "services:\n  app:\n    image: alpine\n",
    ]);
    $service->secretManagerLink()->create(['integration_token_id' => test()->token->id]);

    return $service;
}

function remoteSecretLifecyclePostgres(): StandalonePostgresql
{
    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17-alpine',
        'status' => 'running:healthy',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    $database->secretManagerLink()->create(['integration_token_id' => test()->token->id]);

    return $database;
}

/**
 * Returns the .env file content that saveComposeConfigs() writes to the server.
 */
function remoteSecretLifecycleWrittenEnvFile(Service $service): string
{
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result(output: '');
    });

    $service->saveComposeConfigs();

    preg_match("/echo '([A-Za-z0-9+\/=]+)' \| base64 -d \| tee [^ ]+\.env\.tmp/", $commands->implode("\n"), $matches);

    return base64_decode($matches[1] ?? '');
}

/*
 * Expected values were checked with `docker compose config` (Compose v2 dotenv parser): single quotes
 * keep everything literal, including newlines and "#"; a backslash before the closing single quote
 * escapes it; double quotes need \\, \" and $$.
 */
dataset('remote secret dotenv values', [
    'newline that would add a variable' => ["line1\nSERVICE_PASSWORD_APP=injected", "'line1\nSERVICE_PASSWORD_APP=injected'"],
    'single quote and dollar' => ["it's \$HOME", '"it\'s $$HOME"'],
    'double quote and backslash' => ['say "hi" \\o/', "'say \"hi\" \\o/'"],
    'trailing backslash' => ['ends with \\', '"ends with \\\\"'],
    'comment marker' => ['value #not-a-comment', "'value #not-a-comment'"],
    'dollar' => ['p4$$word', "'p4$\$word'"],
]);

test('remote secret values are written to the service .env file as exact dotenv literals', function (string $secret, string $expected) {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['API_KEY' => $secret])]);
    $service = remoteSecretLifecycleService();
    $service->environment_variables()->create(['key' => 'API_KEY', 'value' => '{{vault.API_KEY}}']);

    expect(remoteSecretLifecycleWrittenEnvFile($service))->toContain("API_KEY={$expected}");
})->with('remote secret dotenv values');

test('literal service variables with remote secrets are written as valid dotenv literals', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['API_KEY' => "it's"])]);
    $service = remoteSecretLifecycleService();
    $service->environment_variables()->create(['key' => 'API_KEY', 'value' => '{{vault.API_KEY}}', 'is_literal' => true]);

    expect(remoteSecretLifecycleWrittenEnvFile($service))->toContain('API_KEY="it\'s"');
});

test('service variables without remote secrets keep their .env format', function () {
    $service = remoteSecretLifecycleService();
    $service->environment_variables()->create(['key' => 'PLAIN', 'value' => 'say "hi"']);

    expect(remoteSecretLifecycleWrittenEnvFile($service))->toContain('PLAIN=say \"hi\"');
    Http::assertNothingSent();
});

test('the application deployment formats remote secrets with the same dotenv rules', function (string $secret, string $expected) {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    expect((new ReflectionMethod($job, 'format_remote_secret_value'))->invoke($job, $secret))->toBe($expected);
})->with('remote secret dotenv values');

test('remote secret values reach the database container environment unchanged', function () {
    $secret = "pa\$word \"q\" 'q' \\ #x\nNEXT=1";
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['PG_PASSWORD' => $secret])]);
    $database = remoteSecretLifecyclePostgres();
    $database->runtime_environment_variables()->create(['key' => 'POSTGRES_PASSWORD', 'value' => '{{vault.PG_PASSWORD}}']);
    $database->runtime_environment_variables()->create(['key' => 'LITERAL_PASSWORD', 'value' => '{{vault.PG_PASSWORD}}', 'is_literal' => true]);

    $action = new StartPostgresql;
    $action->database = $database;
    $environmentVariables = (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);

    // Compose environment entries are not dotenv: quotes stay part of the value and only "$" is interpolated.
    $composeValue = str_replace('$', '$$', $secret);
    expect($environmentVariables)->toContain("POSTGRES_PASSWORD={$composeValue}")
        ->and($environmentVariables)->toContain("LITERAL_PASSWORD={$composeValue}");
});

test('redis remote passwords are escaped the same way in the environment and the start command', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['REDIS_PASSWORD' => 'p4$word'])]);
    $redis = StandaloneRedis::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'redis',
        'image' => 'redis:7-alpine',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $redis->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $redis->runtime_environment_variables()->create(['key' => 'REDIS_PASSWORD', 'value' => '{{vault.REDIS_PASSWORD}}']);

    $action = new StartRedis;
    $action->database = $redis;
    $environmentVariables = (new ReflectionMethod($action, 'generate_environment_variables'))->invoke($action);
    $startCommand = (new ReflectionMethod($action, 'buildStartCommand'))->invoke($action);

    expect($environmentVariables)->toContain('REDIS_PASSWORD=p4$$word')
        ->and($startCommand)->toContain('--requirepass p4$$word');
});

test('a service restart keeps the service running when remote secrets cannot be fetched', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['messages' => ['Doppler is down']], 503)]);
    StopService::shouldRun()->never();
    $service = remoteSecretLifecycleService();
    $service->environment_variables()->create(['key' => 'API_KEY', 'value' => '{{vault.API_KEY}}']);

    expect(fn () => StartService::run($service, stopBeforeStart: true))
        ->toThrow(RuntimeException::class, 'Doppler is down');
});

test('a literal service variable keeps reference text when no secret manager source is configured', function () {
    Http::fake();
    $service = remoteSecretLifecycleService();
    $service->secretManagerLink()->delete();
    $service->environment_variables()->create(['key' => 'TEMPLATE', 'value' => 'Hello {{vault.NAME}}', 'is_literal' => true]);

    $service->ensureRemoteSecretsResolvable($service->environment_variables()->get());

    expect(remoteSecretLifecycleWrittenEnvFile($service))->toContain("TEMPLATE='Hello {{vault.NAME}}'");
    Http::assertNothingSent();
});

test('a service start stops before the service when a non-literal variable references a secret without a source', function () {
    StopService::shouldRun()->never();
    $service = remoteSecretLifecycleService();
    $service->secretManagerLink()->delete();
    $service->environment_variables()->create(['key' => 'API_KEY', 'value' => '{{vault.API_KEY}}']);

    expect(fn () => StartService::run($service, stopBeforeStart: true))
        ->toThrow(RemoteSecretException::class, 'no secret manager source is configured');
});

test('a service restart fetches remote secrets only once', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['API_KEY' => 'value'])]);
    Process::fake(['*' => Process::result(output: '')]);
    StopService::shouldRun();
    $service = remoteSecretLifecycleService();
    $service->environment_variables()->create(['key' => 'API_KEY', 'value' => '{{vault.API_KEY}}']);

    StartService::run($service, stopBeforeStart: true);

    Http::assertSentCount(1);
});

test('a database restart keeps the database running and reports the secret manager error', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['messages' => ['Doppler is down']], 503)]);
    StopDatabase::shouldRun()->never();
    $database = remoteSecretLifecyclePostgres();
    $database->runtime_environment_variables()->create(['key' => 'POSTGRES_PASSWORD', 'value' => '{{vault.PG_PASSWORD}}']);

    expect(RestartDatabase::run($database))->toBeString()->toContain('Doppler is down');
});

test('a database start job shows the secret manager error instead of a generic failure', function () {
    Event::fake([DatabaseStatusChanged::class]);
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['messages' => ['Doppler is down']], 503)]);
    $database = remoteSecretLifecyclePostgres();
    $database->runtime_environment_variables()->create(['key' => 'POSTGRES_PASSWORD', 'value' => '{{vault.PG_PASSWORD}}']);
    $activity = activity()
        ->withProperties([
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => $database->uuid,
            'status' => ProcessStatus::QUEUED->value,
            'team_id' => $this->team->id,
            'operation' => 'database-start',
        ])
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');

    expect(fn () => DatabaseStartJob::dispatchSync(StandalonePostgresql::class, $database->id, $this->team->id, $activity->id, null))
        ->toThrow(RuntimeException::class);

    expect(data_get($activity->refresh(), 'properties.error'))->toContain('Doppler is down');
});

test('the secret key autocomplete reuses recently fetched key names', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['B_KEY' => 'b', 'A_KEY' => 'a'])]);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $env = $application->environment_variables()->create(['key' => 'MY_VAR', 'value' => 'plain']);
    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])->instance();

    expect($component->fetchSecretManagerKeys())->toBe(['A_KEY', 'B_KEY'])
        ->and($component->fetchSecretManagerKeys())->toBe(['A_KEY', 'B_KEY']);
    Http::assertSentCount(1);
});

test('the secret key autocomplete limits how often a user can contact the secret manager', function () {
    Http::fake([REMOTE_SECRET_LIFECYCLE_DOPPLER_URL => Http::response(['A_KEY' => 'a'])]);
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $link = $application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $env = $application->environment_variables()->create(['key' => 'MY_VAR', 'value' => 'plain']);
    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application']);

    // A changed source is fetched again, so each request reaches the secret manager.
    foreach (range(1, 10) as $attempt) {
        $link->update(['settings' => ['project' => "project-{$attempt}", 'config' => 'prd']]);
        $component->call('fetchSecretManagerKeys');
    }
    $link->update(['settings' => ['project' => 'project-11', 'config' => 'prd']]);

    expect(fn () => $component->call('fetchSecretManagerKeys'))->toThrow(RuntimeException::class, 'Too many');
    Http::assertSentCount(10);
});
