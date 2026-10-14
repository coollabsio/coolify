<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\Service\RestartService;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const MCP_LOG_CANARY = 'coolify-canary-mcp-env-value';

beforeEach(function () {
    Server::flushIdentityMap();
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $destinationAttributes = ['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $this->application = Application::factory()->create(['environment_id' => $environment->id, 'status' => 'running:healthy', ...$destinationAttributes]);
    $this->service = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $this->server->id, ...$destinationAttributes]);

    foreach ([$this->application, $this->service] as $resource) {
        EnvironmentVariable::query()->create([
            'key' => 'MCP_CANARY',
            'value' => MCP_LOG_CANARY,
            'resourceable_type' => $resource->getMorphClass(),
            'resourceable_id' => $resource->id,
            'is_preview' => false,
        ]);
    }
});

/**
 * @param  list<string>  $abilities
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpLogRedactionCall(array $abilities, string $tool, array $arguments): array
{
    auth()->forgetGuards();
    $token = test()->user->createToken('mcp-log-redaction', $abilities)->plainTextToken;

    $response = test()->withToken($token)->withHeaders(['Accept' => 'application/json, text/event-stream'])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ])->assertOk();

    return $response->json();
}

function fakeMcpApplicationContainer(Closure $logs): void
{
    Process::fake(fn ($process) => match (true) {
        str_contains($process->command, 'docker ps') => Process::result(output: json_encode(['ID' => 'abc123', 'Names' => 'app', 'Labels' => '', 'State' => 'running'])),
        str_contains($process->command, 'docker inspect') => Process::result(output: json_encode(['State' => ['Status' => 'running']])),
        str_contains($process->command, 'docker logs') => $logs(),
        default => Process::result(output: ''),
    });
}

it('redacts the application environment values in get_logs output', function () {
    fakeMcpApplicationContainer(fn () => Process::result(output: 'booted with '.MCP_LOG_CANARY));

    $response = mcpLogRedactionCall(['read', 'read:sensitive'], 'get_logs', ['resource' => 'application', 'uuid' => $this->application->uuid]);
    $data = json_decode($response['result']['content'][0]['text'], true)['data'];

    expect($data['logs'])->toBe('booted with '.REDACTED)
        ->and(json_encode($response))->not->toContain(MCP_LOG_CANARY);
});

it('redacts the application environment values in get_logs error messages', function () {
    fakeMcpApplicationContainer(fn () => Process::result(errorOutput: 'logs failed near '.MCP_LOG_CANARY, exitCode: 1));

    $response = mcpLogRedactionCall(['read', 'read:sensitive'], 'get_logs', ['resource' => 'application', 'uuid' => $this->application->uuid]);
    $data = json_decode($response['result']['content'][0]['text'], true)['data'];

    expect($data['reason'])->toBe('log_fetch_failed')
        ->and($data['message'])->toBe('logs failed near '.REDACTED)
        ->and(json_encode($response))->not->toContain(MCP_LOG_CANARY);
});

it('redacts the service environment values in control error messages', function () {
    RestartService::partialMock()->shouldReceive('handle')->andThrow(new RuntimeException('restart failed near '.MCP_LOG_CANARY));

    $response = mcpLogRedactionCall(['read', 'deploy'], 'control', ['resource' => 'service', 'action' => 'restart', 'uuid' => $this->service->uuid]);

    expect(json_encode($response))->toContain('restart failed near')
        ->not->toContain(MCP_LOG_CANARY);
});

it('redacts standalone database passwords in get_logs output and errors', function (bool $fails) {
    $database = StandalonePostgresql::query()->create([
        'name' => 'log-redaction-database',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'postgres_password' => 'xy',
        'status' => 'running:healthy',
    ]);
    fakeMcpApplicationContainer(fn () => $fails
        ? Process::result(errorOutput: 'failed near xy', exitCode: 1)
        : Process::result(output: 'booted xy'));

    $response = mcpLogRedactionCall(['read', 'read:sensitive'], 'get_logs', ['resource' => 'database', 'uuid' => $database->uuid]);
    $data = json_decode($response['result']['content'][0]['text'], true)['data'];

    expect($data[$fails ? 'message' : 'logs'])->toBe(($fails ? 'failed near ' : 'booted ').REDACTED);
})->with([false, true]);

it('redacts standalone database passwords in control error messages', function () {
    $database = StandalonePostgresql::query()->create([
        'name' => 'control-redaction-database',
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
        'postgres_password' => 'control-db-password',
    ]);
    RestartDatabase::partialMock()->shouldReceive('handle')->andThrow(new RuntimeException('restart failed near control-db-password'));

    $response = mcpLogRedactionCall(['read', 'deploy'], 'control', ['resource' => 'database', 'action' => 'restart', 'uuid' => $database->uuid]);

    expect(json_encode($response))->toContain('restart failed near')
        ->not->toContain('control-db-password');
});
