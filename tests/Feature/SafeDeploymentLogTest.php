<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Traits\ExecuteRemoteCommand;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSafeDeploymentLogFixture(): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
    ]);

    return [$application, $deployment];
}

function createSafeDeploymentLogVariable(Application $application, string $key, string $value, bool $locked): void
{
    EnvironmentVariable::query()->create([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'is_preview' => false,
        'is_shown_once' => $locked,
    ]);
}

it('redacts every application environment value before storing deployment logs', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $secret = 'coolify-canary-normal-environment-secret';

    EnvironmentVariable::query()->create([
        'key' => 'NORMAL_ENVIRONMENT_VALUE',
        'value' => $secret,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'is_preview' => false,
        'is_shown_once' => false,
    ]);

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => fake()->uuid(),
    ]);

    $deployment->addLogEntry(
        message: "process returned {$secret}",
        command: "deploy --credential={$secret}",
    );

    $storedLogs = $deployment->refresh()->getRawOriginal('logs');

    expect($storedLogs)
        ->not->toContain($secret)
        ->toContain(REDACTED);
});

it('redacts only locked environment values when the application allows it', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $application->settings->update(['redact_all_env_values_in_logs' => false]);
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'visible-production-value', locked: false);
    createSafeDeploymentLogVariable($application, 'LOCKED_VALUE', 'coolify-canary-locked-value', locked: true);

    $deployment->addLogEntry('env visible-production-value coolify-canary-locked-value');

    $output = json_decode($deployment->refresh()->logs, true)[0]['output'];

    expect($output)->toBe('env visible-production-value '.REDACTED);
});

it('adds one hint to the deployment log when values that are not locked are hidden', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'hidden-production-value', locked: false);

    $deployment->addLogEntry('env hidden-production-value');
    $deployment->addLogEntry('again hidden-production-value');
    ApplicationDeploymentQueue::query()->findOrFail($deployment->id)->addLogEntry('other worker hidden-production-value');

    $outputs = collect(json_decode($deployment->refresh()->logs, true))->pluck('output');

    expect($outputs->all())->toBe([
        'env '.REDACTED,
        ApplicationDeploymentQueue::HIDDEN_ENV_VALUES_HINT,
        'again '.REDACTED,
        'other worker '.REDACTED,
    ]);
});

it('adds no hidden values hint when only locked values are hidden', function (bool $redactAll) {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $application->settings->update(['redact_all_env_values_in_logs' => $redactAll]);
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'plain-production-value', locked: false);
    createSafeDeploymentLogVariable($application, 'LOCKED_VALUE', 'coolify-canary-locked-value', locked: true);

    $deployment->addLogEntry('env coolify-canary-locked-value');
    $deployment->addLogEntry($redactAll ? 'no env value here' : 'env plain-production-value');

    expect(collect(json_decode($deployment->refresh()->logs, true))->pluck('output'))
        ->not->toContain(ApplicationDeploymentQueue::HIDDEN_ENV_VALUES_HINT);
})->with(['redact all on' => true, 'redact all off' => false]);

it('keeps redacting all values for other applications in the same team', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    createSafeDeploymentLogVariable($application, 'APP_ENV_NAME', 'hidden-production-value', locked: false);
    $otherApplication = Application::factory()->create(['environment_id' => $application->environment_id]);
    $otherApplication->settings->update(['redact_all_env_values_in_logs' => false]);

    $deployment->addLogEntry('env hidden-production-value');

    expect($application->settings->fresh()->redact_all_env_values_in_logs)->toBeTrue()
        ->and(json_decode($deployment->refresh()->logs, true)[0]['output'])->toBe('env '.REDACTED);
});

it('redacts remote secrets registered on the deployment', function () {
    [, $deployment] = makeSafeDeploymentLogFixture();
    $deployment->redactRemoteSecrets(['API_TOKEN' => 'coolify-canary-remote-secret', 'PIN' => '4821']);

    $deployment->addLogEntry(
        message: 'output coolify-canary-remote-secret pin 4821',
        command: 'echo coolify-canary-remote-secret',
    );

    expect($deployment->refresh()->getRawOriginal('logs'))
        ->not->toContain('coolify-canary-remote-secret')
        ->not->toContain('4821')
        ->toContain(REDACTED);
});

it('always redacts short locked values and values of locked shared variables', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $application->settings->update(['redact_all_env_values_in_logs' => false]);
    createSafeDeploymentLogVariable($application, 'PIN', '4821', locked: true);
    SharedEnvironmentVariable::query()->create([
        'key' => 'SHARED_DB_PASS',
        'value' => 'coolify-canary-shared-locked',
        'type' => 'team',
        'team_id' => $application->team()->id,
        'is_shown_once' => true,
    ]);
    createSafeDeploymentLogVariable($application, 'DB_PASS', '{{team.SHARED_DB_PASS}}', locked: false);

    $deployment->addLogEntry('pin 4821 password coolify-canary-shared-locked');

    expect(json_decode($deployment->refresh()->logs, true)[0]['output'])
        ->toBe('pin '.REDACTED.' password '.REDACTED);
});

it('passes remote secrets from the deployment job into stored log entries', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $job = new class
    {
        use ExecuteRemoteCommand;

        public $application;

        public $application_deployment_queue;

        public ?array $remote_secrets_cache = ['API_TOKEN' => 'coolify-canary-vault-secret'];
    };
    $job->application = $application;
    $job->application_deployment_queue = $deployment;

    (new ReflectionMethod($job, 'addRetryLogEntry'))->invoke($job, 1, 3, 1, 'failed near coolify-canary-vault-secret');

    expect($deployment->refresh()->getRawOriginal('logs'))
        ->not->toContain('coolify-canary-vault-secret')
        ->toContain(REDACTED);
});

it('caches normalized remote secrets only after they are loaded', function () {
    $job = new class
    {
        use ExecuteRemoteCommand;

        public ?array $remote_secrets_cache = null;
    };
    $redact = new ReflectionMethod($job, 'redact_sensitive_info');

    expect($redact->invoke($job, 'ordinary output'))->toBe('ordinary output');

    $job->remote_secrets_cache = ['PIN' => '4821'];
    expect($redact->invoke($job, 'pin 4821'))->toBe('pin '.REDACTED);

    $cache = new ReflectionProperty($job, 'remoteSecretLogRedactionValues');
    $normalized = $cache->getValue($job);
    expect($normalized)->toContain('4821');

    expect($redact->invoke($job, 'another pin 4821'))->toBe('another pin '.REDACTED)
        ->and($cache->getValue($job))->toBe($normalized);

    $otherJob = new ($job::class);
    $otherJob->remote_secrets_cache = ['PIN' => '7392'];
    expect($redact->invoke($otherJob, 'pin 7392'))->toBe('pin '.REDACTED);
});

it('keeps corrupted stored logs and warns once instead of overwriting them', function () {
    [, $deployment] = makeSafeDeploymentLogFixture();
    ApplicationDeploymentQueue::query()->whereKey($deployment->id)->update(['logs' => '{not json']);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['deployment_uuid' => $deployment->deployment_uuid]);

    $deployment->addLogEntry('fresh entry');
    $deployment->addLogEntry('second entry');

    expect($deployment->refresh()->getRawOriginal('logs'))->toBe('{not json');
});

/**
 * A deployment job whose remote command sends its output in the given chunks, like a real SSH stream.
 *
 * @param  list<array{0: string, 1: string}>  $chunks
 */
function runDeploymentCommandWithChunks(ApplicationDeploymentQueue $deployment, array $chunks, bool $hidden = false): void
{
    $team = $deployment->application->team();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $job = new class
    {
        use ExecuteRemoteCommand;

        public $application;

        public $application_deployment_queue;

        public $server;

        public $saved_outputs;
    };
    $job->application = $deployment->application;
    $job->application_deployment_queue = $deployment;
    $job->server = $server;
    $job->saved_outputs = collect();
    // The process fake ends every output chunk with a newline, so the chunks are sent by hand.
    $invoked = Mockery::mock(InvokedProcess::class);
    $invoked->shouldReceive('id')->andReturn(123);
    Process::shouldReceive('timeout->idleTimeout->input->start')->andReturnUsing(function (string $command, Closure $output) use ($invoked, $chunks) {
        $invoked->shouldReceive('wait')->andReturnUsing(function () use ($output, $chunks) {
            foreach ($chunks as [$type, $chunk]) {
                $output($type, $chunk);
            }

            return new FakeProcessResult;
        });

        return $invoked;
    });

    (new ReflectionMethod($job, 'executeCommandWithProcess'))->invoke($job, 'cat output', $hidden, null, true, false);
}

it('redacts a deployment output secret that is split across output chunks', function () {
    [$application, $deployment] = makeSafeDeploymentLogFixture();
    $application->settings->update(['redact_all_env_values_in_logs' => false]);
    createSafeDeploymentLogVariable($application, 'LOCKED_VALUE', 'coolify-canary-locked-value', locked: true);

    runDeploymentCommandWithChunks($deployment, [
        ['out', 'value is coolify-canary-lo'],
        ['err', 'error coolify-canary-'],
        ['out', "cked-value\nnext "],
        ['err', "locked-value\n"],
        ['out', 'line coolify-canary-locked-value'],
    ], hidden: true);

    $logs = collect(json_decode($deployment->refresh()->logs, true));

    expect($logs->map(fn (array $entry) => [$entry['type'], $entry['output']])->all())->toBe([
        ['stdout', 'value is '.REDACTED],
        ['stderr', 'error '.REDACTED],
        ['stdout', 'next line '.REDACTED],
    ])
        ->and($logs->pluck('hidden')->unique()->all())->toBe([true])
        ->and($logs->pluck('command')->unique()->all())->toBe(['cat output']);
});

it('redacts a private key that is split across deployment output chunks', function () {
    [, $deployment] = makeSafeDeploymentLogFixture();

    runDeploymentCommandWithChunks($deployment, [
        ['out', "before\n-----BEGIN RSA PRIVATE KEY-----\nKEYBODYLINEONE\n"],
        ['out', "KEYBODYLINETWO\n-----END RSA PRIVATE KEY-----\nafter\n"],
    ]);

    $outputs = collect(json_decode($deployment->refresh()->logs, true))->pluck('output');

    expect($outputs->implode("\n"))->not->toContain('KEYBODYLINE')
        ->and($outputs->all())->toBe(['before', REDACTED."\nafter"]);
});

/**
 * A team with an API token, a functional server, and running containers whose logs print an environment value
 * and a pattern secret. Returns the endpoint path for the requested resource.
 */
function makeRestLogRedactionEndpoint(string $resource, string $envSecret): array
{
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'logs',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['root'],
        'team_id' => $team->id,
    ]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destinationAttributes = ['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    if ($resource === 'database') {
        $owner = null;
        $database = StandalonePostgresql::create([
            'name' => 'logs-db',
            'postgres_user' => 'postgres',
            'postgres_password' => 'postgres',
            'postgres_db' => 'app',
            'image' => 'postgres:16-alpine',
            'environment_id' => $environment->id,
            ...$destinationAttributes,
        ]);
        $path = "/api/v1/databases/{$database->uuid}/logs";
        $containerName = $database->uuid;
    } elseif ($resource === 'application') {
        $owner = Application::factory()->create(['environment_id' => $environment->id, ...$destinationAttributes]);
        $path = "/api/v1/applications/{$owner->uuid}/logs";
        $containerName = $owner->uuid;
    } else {
        $owner = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $server->id, ...$destinationAttributes]);
        $child = $resource === 'service database'
            ? ServiceDatabase::create(['uuid' => (string) Str::uuid(), 'name' => 'db', 'service_id' => $owner->id])
            : ServiceApplication::create(['uuid' => (string) Str::uuid(), 'name' => 'web', 'service_id' => $owner->id]);
        $containerName = "{$child->name}-{$owner->uuid}";
        $path = match ($resource) {
            'service' => "/api/v1/services/{$owner->uuid}/logs?sub_service_name=web",
            'service application' => "/api/v1/services/{$owner->uuid}/applications/{$child->uuid}/logs",
            'service database' => "/api/v1/services/{$owner->uuid}/databases/{$child->uuid}/logs",
        };
    }

    if ($owner !== null) {
        EnvironmentVariable::query()->create([
            'key' => 'LOG_CANARY',
            'value' => $envSecret,
            'resourceable_type' => $owner->getMorphClass(),
            'resourceable_id' => $owner->id,
            'is_preview' => false,
        ]);
    }

    Process::fake(function ($process) use ($containerName, $envSecret) {
        return match (true) {
            str_contains($process->command, 'docker ps') => Process::result(output: json_encode([
                'ID' => 'abc123',
                'Names' => $containerName,
                'Labels' => "coolify.name={$containerName}",
                'State' => 'running',
            ])),
            str_contains($process->command, 'docker inspect') => Process::result(output: json_encode(['State' => ['Status' => 'running']])),
            str_contains($process->command, 'docker logs') => Process::result(output: "booted with {$envSecret}\npassword=coolify-canary-pattern-secret"),
            default => Process::result(output: ''),
        };
    });

    return [$path, $token->getKey().'|'.$plainTextToken];
}

it('redacts environment values and secrets in each REST container log endpoint', function (string $resource) {
    $envSecret = 'coolify-canary-env-value-in-logs';
    [$path, $bearerToken] = makeRestLogRedactionEndpoint($resource, $envSecret);

    $logs = $this->withToken($bearerToken)->getJson($path)->assertOk()->json('logs');

    expect($logs)
        ->not->toContain('coolify-canary-pattern-secret')
        ->toContain('password='.REDACTED);
    if ($resource !== 'database') {
        expect($logs)->toContain('booted with '.REDACTED)->not->toContain($envSecret);
    }
})->with(['application', 'database', 'service', 'service application', 'service database']);
