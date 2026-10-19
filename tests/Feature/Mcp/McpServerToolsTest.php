<?php

use App\Actions\Destination\RemoveStandaloneDockerNetwork;
use App\Jobs\DockerCleanupJob;
use App\Jobs\RestartProxyJob;
use App\Models\AuditEvent;
use App\Models\CloudProviderToken;
use App\Models\DiscordNotificationSettings;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Models\User;
use App\Services\SentinelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

const MCP_SRV_NEW_PRIVATE_KEY = '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACAuIzppW7xQGiSHBiMiQxJlsTq+XfUhnrUCHwYbbaLF/QAAAJDAOcCswDnA
rAAAAAtzc2gtZWQyNTUxOQAAACAuIzppW7xQGiSHBiMiQxJlsTq+XfUhnrUCHwYbbaLF/Q
AAAECKtKd242pj0qdKWaOpXAQFI2ZGLQOg9vCP2+o+LPLMtS4jOmlbvFAaJIcGIyJDEmWx
Or5d9SGetQIfBhttosX9AAAACG1jcC10ZXN0AQIDBAU=
-----END OPENSSH PRIVATE KEY-----';

class McpSrvStubMetricsClient extends SentinelMetricsClient
{
    public static int $calls = 0;

    public function summary(): ?array
    {
        self::$calls++;

        return [
            'online' => true, 'cpu' => 42.5, 'memUsed' => 1024, 'memTotal' => 4096,
            'diskUsed' => 10, 'diskTotal' => 100, 'diskPercent' => 10.0,
            'load1' => 0.5, 'netRx' => 5.0, 'netTx' => 1.0,
        ];
    }

    public function history(string $metric, string $range): array
    {
        self::$calls++;
        if ($metric === 'disk') {
            throw new RuntimeException('Disk endpoint unavailable.');
        }

        return [
            [now()->subHours(3)->getTimestampMs(), 1.0],
            [now()->subMinutes(10)->getTimestampMs(), $metric === 'cpu' ? 42.5 : 1024.0],
        ];
    }

    public function networkHistory(string $range): array
    {
        self::$calls++;

        return ['rx' => [[now()->subMinutes(5)->getTimestampMs(), 5.0]], 'tx' => [[now()->subMinutes(5)->getTimestampMs(), 1.0]]];
    }
}

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Process::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Http::preventStrayRequests();
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_mcp_server_enabled' => true, 'is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->otherTeam->members()->attach($this->otherUser->id, ['role' => 'owner']);

    $this->key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->otherKey = PrivateKey::factory()->create(['team_id' => $this->otherTeam->id]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->key->id]);
    $this->otherServer = Server::factory()->create(['team_id' => $this->otherTeam->id, 'private_key_id' => $this->otherKey->id, 'name' => 'Theirs']);

    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function mcpSrvToken(User $user, array $abilities = ['read', 'write']): string
{
    return $user->createToken('mcp-srv', $abilities)->plainTextToken;
}

function mcpSrvCall(string $token, string $tool, array $arguments = [])
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => (object) $arguments],
    ]);
}

function mcpSrvText($response): string
{
    return (string) ($response->json('result.content.0.text') ?? $response->json('error.message'));
}

function mcpSrvIsError($response): bool
{
    return $response->json('result.isError') === true || $response->json('error') !== null;
}

function mcpSrvData($response): mixed
{
    return json_decode(mcpSrvText($response), true)['data'] ?? null;
}

function mcpSrvMakeFunctional(Server $server): void
{
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    Server::flushIdentityMap();
}

function mcpSrvGithubApp(Team $team, PrivateKey $key, array $overrides = []): GithubApp
{
    return GithubApp::create(array_merge([
        'name' => 'gh-'.$team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234,
        'installation_id' => 5678,
        'client_id' => 'client-id',
        'client_secret' => 'GH_CLIENT_SECRET_VALUE',
        'webhook_secret' => 'GH_WEBHOOK_SECRET_VALUE',
        'private_key_id' => $key->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
    ], $overrides));
}

function mcpSrvS3(Team $team, array $overrides = []): S3Storage
{
    return S3Storage::create(array_merge([
        'team_id' => $team->id,
        'name' => 'S3 '.$team->id,
        'region' => 'us-east-1',
        'key' => 'S3_ACCESS_KEY_VALUE',
        'secret' => 'S3_SECRET_KEY_VALUE',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => false,
    ], $overrides));
}

// ---------------------------------------------------------------- shared checks

test('server group write actions reject read-only tokens', function (string $tool, array $arguments) {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['read']), $tool, $arguments + ['confirm' => true]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toMatch('/Missing required permissions: (write|deploy)|Tool \[\w+\] not found/');
})->with([
    'manage_server create' => ['manage_server', ['action' => 'create', 'data' => ['ip' => '203.0.113.50']]],
    'manage_proxy restart' => ['manage_proxy', ['action' => 'restart', 'uuid' => 'x']],
    'docker_cleanup run' => ['docker_cleanup', ['action' => 'run', 'uuid' => 'x']],
    'manage_destination create' => ['manage_destination', ['action' => 'create', 'server_uuid' => 'x']],
    'manage_registry create' => ['manage_registry', ['action' => 'create', 'uuid' => 'x']],
    'manage_private_key create' => ['manage_private_key', ['action' => 'create']],
    'manage_s3_storage create' => ['manage_s3_storage', ['action' => 'create']],
    'manage_git_source create' => ['manage_git_source', ['action' => 'create', 'resource' => 'github']],
    'cloud_provider provision_server' => ['cloud_provider', ['action' => 'provision_server', 'resource' => 'hetzner']],
    'manage_notifications update' => ['manage_notifications', ['action' => 'update', 'resource' => 'discord']],
]);

test('server group write actions reject member tokens', function (string $tool, array $arguments) {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $response = mcpSrvCall(mcpSrvToken($member, ['read', 'write']), $tool, $arguments + ['confirm' => true]);

    expect($response->json('result'))->toBeNull()
        ->and($response->json('error.message'))->toBe('Missing required team role.');
})->with([
    'manage_server update' => ['manage_server', ['action' => 'update', 'data' => ['name' => 'x']]],
    'manage_private_key create' => ['manage_private_key', ['action' => 'create']],
    'manage_registry list' => ['manage_registry', ['action' => 'list']],
    'cloud_provider create_token' => ['cloud_provider', ['action' => 'create_token']],
    'manage_notifications update' => ['manage_notifications', ['action' => 'update', 'resource' => 'email']],
]);

test('server group destructive actions need confirm=true', function (string $tool, array $arguments) {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', 'write', 'deploy']), $tool, $arguments);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('requires confirm=true');
    Queue::assertNothingPushed();
})->with([
    'manage_server delete' => ['manage_server', ['action' => 'delete', 'uuid' => 'x']],
    'manage_proxy update' => ['manage_proxy', ['action' => 'update', 'uuid' => 'x']],
    'manage_proxy set_configuration' => ['manage_proxy', ['action' => 'set_configuration', 'uuid' => 'x']],
    'manage_proxy restart' => ['manage_proxy', ['action' => 'restart', 'uuid' => 'x']],
    'docker_cleanup run' => ['docker_cleanup', ['action' => 'run', 'uuid' => 'x']],
    'manage_destination delete' => ['manage_destination', ['action' => 'delete', 'uuid' => 'x']],
    'manage_registry delete' => ['manage_registry', ['action' => 'delete', 'uuid' => 'x', 'registry' => 'ghcr.io']],
    'manage_private_key delete' => ['manage_private_key', ['action' => 'delete', 'uuid' => 'x']],
    'manage_s3_storage delete' => ['manage_s3_storage', ['action' => 'delete', 'uuid' => 'x']],
    'manage_git_source delete' => ['manage_git_source', ['action' => 'delete', 'resource' => 'github', 'source_id' => '1']],
    'cloud_provider delete_token' => ['cloud_provider', ['action' => 'delete_token', 'uuid' => 'x']],
    'cloud_provider provision_server' => ['cloud_provider', ['action' => 'provision_server', 'resource' => 'hetzner']],
]);

// ---------------------------------------------------------------- manage_server

test('manage_server creates a server in the token team with a team private key', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_server', ['action' => 'create', 'data' => [
        'name' => 'mcp-server', 'ip' => '203.0.113.50', 'private_key_uuid' => $this->key->uuid,
    ]]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response));
    $server = Server::where('name', 'mcp-server')->sole();
    expect($server->team_id)->toBe($this->team->id)
        ->and($server->private_key_id)->toBe($this->key->id);
});

test('manage_server rejects a private key of another team', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_server', ['action' => 'create', 'data' => [
        'name' => 'stolen-key', 'ip' => '203.0.113.51', 'private_key_uuid' => $this->otherKey->uuid,
    ]]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(Server::where('name', 'stolen-key')->exists())->toBeFalse();

    $update = mcpSrvCall(mcpSrvToken($this->user), 'manage_server', ['action' => 'update', 'uuid' => $this->server->uuid, 'data' => ['private_key_uuid' => $this->otherKey->uuid]]);
    expect(mcpSrvIsError($update))->toBeTrue()
        ->and($this->server->fresh()->private_key_id)->toBe($this->key->id);
});

test('manage_server cannot change, validate, or delete a server of another team', function (string $action, array $data) {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_server', ['action' => $action, 'uuid' => $this->otherServer->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue();
    $fresh = Server::find($this->otherServer->id);
    expect($fresh)->not->toBeNull()->and($fresh->name)->toBe('Theirs');
    Queue::assertNothingPushed();
})->with([
    'update' => ['update', ['name' => 'Hijacked']],
    'validate' => ['validate', []],
    'delete' => ['delete', []],
]);

test('manage_server updates, validates, and deletes its own server', function () {
    $token = mcpSrvToken($this->user);

    $update = mcpSrvCall($token, 'manage_server', ['action' => 'update', 'uuid' => $this->server->uuid, 'data' => ['name' => 'renamed']]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and($this->server->fresh()->name)->toBe('renamed');

    $validate = mcpSrvCall($token, 'manage_server', ['action' => 'validate', 'uuid' => $this->server->uuid]);
    expect(mcpSrvIsError($validate))->toBeFalse(mcpSrvText($validate));

    $delete = mcpSrvCall($token, 'manage_server', ['action' => 'delete', 'uuid' => $this->server->uuid, 'confirm' => true]);
    expect(mcpSrvIsError($delete))->toBeFalse(mcpSrvText($delete))
        ->and(Server::find($this->server->id))->toBeNull();
});

// ---------------------------------------------------------------- manage_proxy

test('manage_proxy reads and updates the proxy of its own server and queues a restart', function () {
    $token = mcpSrvToken($this->user, ['read', 'write', 'deploy']);

    $get = mcpSrvCall($token, 'manage_proxy', ['action' => 'get', 'uuid' => $this->server->uuid]);
    expect(mcpSrvIsError($get))->toBeFalse(mcpSrvText($get))
        ->and(mcpSrvData($get))->toHaveKey('proxy_type');

    $update = mcpSrvCall($token, 'manage_proxy', ['action' => 'update', 'uuid' => $this->server->uuid, 'confirm' => true, 'data' => ['generate_exact_labels' => true]]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and((bool) $this->server->settings()->first()->generate_exact_labels)->toBeTrue();

    $restart = mcpSrvCall($token, 'manage_proxy', ['action' => 'restart', 'uuid' => $this->server->uuid, 'confirm' => true]);
    expect(mcpSrvIsError($restart))->toBeFalse(mcpSrvText($restart));
    Queue::assertPushed(RestartProxyJob::class);
});

test('manage_proxy cannot read or change the proxy of another team', function (string $action, array $data) {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', 'write', 'deploy']), 'manage_proxy', ['action' => $action, 'uuid' => $this->otherServer->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and((bool) $this->otherServer->settings()->first()->generate_exact_labels)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'get' => ['get', []],
    'update' => ['update', ['generate_exact_labels' => true]],
    'set_configuration' => ['set_configuration', ['configuration' => "services:\n  traefik:\n    image: evil\n"]],
    'restart' => ['restart', []],
]);

// ---------------------------------------------------------------- docker_cleanup

test('docker_cleanup reads, updates, lists, and runs cleanup on its own server', function () {
    $token = mcpSrvToken($this->user, ['read', 'write', 'deploy']);

    $get = mcpSrvCall($token, 'docker_cleanup', ['action' => 'get', 'uuid' => $this->server->uuid]);
    expect(mcpSrvIsError($get))->toBeFalse(mcpSrvText($get))
        ->and(mcpSrvData($get))->toHaveKey('docker_cleanup_threshold');

    $update = mcpSrvCall($token, 'docker_cleanup', ['action' => 'update', 'uuid' => $this->server->uuid, 'data' => ['docker_cleanup_threshold' => 55]]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and((int) $this->server->settings()->first()->docker_cleanup_threshold)->toBe(55);

    $executions = mcpSrvCall($token, 'docker_cleanup', ['action' => 'executions', 'uuid' => $this->server->uuid]);
    expect(mcpSrvIsError($executions))->toBeFalse(mcpSrvText($executions));

    $run = mcpSrvCall($token, 'docker_cleanup', ['action' => 'run', 'uuid' => $this->server->uuid, 'confirm' => true]);
    expect(mcpSrvIsError($run))->toBeFalse(mcpSrvText($run));
    Queue::assertPushed(DockerCleanupJob::class);
});

test('docker_cleanup cannot touch a server of another team', function (string $action, array $data) {
    $before = (int) $this->otherServer->settings()->first()->docker_cleanup_threshold;

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', 'write', 'deploy']), 'docker_cleanup', ['action' => $action, 'uuid' => $this->otherServer->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and((int) $this->otherServer->settings()->first()->docker_cleanup_threshold)->toBe($before);
    Queue::assertNothingPushed();
})->with([
    'get' => ['get', []],
    'update' => ['update', ['docker_cleanup_threshold' => 12]],
    'executions' => ['executions', []],
    'run' => ['run', []],
]);

// ---------------------------------------------------------------- manage_destination

test('manage_destination creates, renames, and deletes a destination on its own server', function () {
    $cleanup = Mockery::mock(RemoveStandaloneDockerNetwork::class);
    $cleanup->shouldReceive('handle')->once();
    $this->app->instance(RemoveStandaloneDockerNetwork::class, $cleanup);
    $token = mcpSrvToken($this->user);

    $create = mcpSrvCall($token, 'manage_destination', ['action' => 'create', 'server_uuid' => $this->server->uuid, 'data' => ['network' => 'mcp-net', 'name' => 'mcp-dest']]);
    expect(mcpSrvIsError($create))->toBeFalse(mcpSrvText($create));
    $destination = StandaloneDocker::where('network', 'mcp-net')->sole();
    expect($destination->server_id)->toBe($this->server->id);

    $update = mcpSrvCall($token, 'manage_destination', ['action' => 'update', 'uuid' => $destination->uuid, 'data' => ['name' => 'renamed-dest']]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and($destination->fresh()->name)->toBe('renamed-dest');

    $delete = mcpSrvCall($token, 'manage_destination', ['action' => 'delete', 'uuid' => $destination->uuid, 'confirm' => true]);
    expect(mcpSrvIsError($delete))->toBeFalse(mcpSrvText($delete))
        ->and($destination->fresh())->toBeNull();
});

test('manage_destination cannot create a destination on a server of another team', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_destination', ['action' => 'create', 'server_uuid' => $this->otherServer->uuid, 'data' => ['network' => 'evil-net']]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(StandaloneDocker::where('network', 'evil-net')->exists())->toBeFalse();
});

test('manage_destination cannot rename or delete a destination of another team', function (string $action, array $data) {
    $cleanup = Mockery::mock(RemoveStandaloneDockerNetwork::class);
    $cleanup->shouldNotReceive('handle');
    $this->app->instance(RemoveStandaloneDockerNetwork::class, $cleanup);
    $foreign = StandaloneDocker::where('server_id', $this->otherServer->id)->firstOrFail();
    $name = $foreign->name;

    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_destination', ['action' => $action, 'uuid' => $foreign->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe($name);
})->with([
    'update' => ['update', ['name' => 'Hijacked']],
    'delete' => ['delete', []],
]);

// ---------------------------------------------------------------- manage_registry

test('manage_registry lists registry logins without passwords', function () {
    mcpSrvMakeFunctional($this->server);
    Process::fake(fn () => Process::result(output: json_encode([
        'auths' => ['ghcr.io' => ['auth' => base64_encode('octocat:REGISTRY_PASSWORD_VALUE')]],
    ])));

    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'manage_registry', ['action' => 'list', 'uuid' => $this->server->uuid]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->toContain('ghcr.io')
        ->and(mcpSrvText($response))->not->toContain('REGISTRY_PASSWORD_VALUE')
        ->and(mcpSrvText($response))->not->toContain(base64_encode('octocat:REGISTRY_PASSWORD_VALUE'));
});

test('manage_registry list needs read:sensitive', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', 'write']), 'manage_registry', ['action' => 'list', 'uuid' => $this->server->uuid]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('read:sensitive');
});

test('manage_registry logs in on its own server without echoing the password', function () {
    mcpSrvMakeFunctional($this->server);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', 'write', 'deploy']), 'manage_registry', ['action' => 'create', 'uuid' => $this->server->uuid, 'data' => [
        'registry' => 'ghcr.io', 'username' => 'octocat', 'password' => 'REGISTRY_PASSWORD_VALUE',
    ]]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->not->toContain('REGISTRY_PASSWORD_VALUE');
    Process::assertRan(fn ($process) => str_contains(is_array($process->command) ? implode(' ', $process->command) : $process->command, 'docker'));
});

test('manage_registry cannot touch registries on a server of another team', function (string $action, string $ability, array $extra) {
    mcpSrvMakeFunctional($this->otherServer);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read', $ability]), 'manage_registry', ['action' => $action, 'uuid' => $this->otherServer->uuid, 'confirm' => true] + $extra);

    expect(mcpSrvIsError($response))->toBeTrue();
    Process::assertNothingRan();
})->with([
    'list' => ['list', 'read:sensitive', []],
    'create' => ['create', 'write', ['data' => ['registry' => 'ghcr.io', 'username' => 'evil', 'password' => 'x']]],
    'check' => ['check', 'write', ['registry' => 'ghcr.io']],
    'delete' => ['delete', 'write', ['registry' => 'ghcr.io']],
]);

// ---------------------------------------------------------------- manage_private_key

test('manage_private_key never returns key material, even to root and read:sensitive tokens', function (array $abilities) {
    $token = mcpSrvToken($this->user, $abilities);
    $keyBody = 'QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi';

    $list = mcpSrvCall($token, 'manage_private_key', ['action' => 'list']);
    expect(mcpSrvIsError($list))->toBeFalse(mcpSrvText($list))
        ->and(mcpSrvText($list))->toContain($this->key->uuid)
        ->and(mcpSrvText($list))->not->toContain('PRIVATE KEY')
        ->and(mcpSrvText($list))->not->toContain($keyBody)
        ->and(mcpSrvText($list))->not->toContain($this->otherKey->uuid);

    $get = mcpSrvCall($token, 'manage_private_key', ['action' => 'get', 'uuid' => $this->key->uuid]);
    expect(mcpSrvIsError($get))->toBeFalse(mcpSrvText($get))
        ->and(mcpSrvText($get))->not->toContain('PRIVATE KEY')
        ->and(mcpSrvText($get))->not->toContain($keyBody);
})->with([
    'root' => [['root']],
    'read:sensitive' => [['read', 'read:sensitive']],
]);

test('manage_private_key create does not return or audit the key material', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'manage_private_key', ['action' => 'create', 'data' => [
        'name' => 'mcp-key', 'private_key' => MCP_SRV_NEW_PRIVATE_KEY,
    ]]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->not->toContain('PRIVATE KEY')
        ->and(mcpSrvText($response))->not->toContain('AAAECKtKd242pj0qdKWaOpXAQFI2ZGLQOg9vCP2');
    expect(PrivateKey::where('name', 'mcp-key')->sole()->team_id)->toBe($this->team->id);

    $audit = AuditEvent::query()->get()->toJson();
    expect($audit)->not->toContain('PRIVATE KEY')
        ->and($audit)->not->toContain('AAAECKtKd242pj0qdKWaOpXAQFI2ZGLQOg9vCP2');
});

test('manage_private_key cannot read, change, or delete a key of another team', function (string $action, array $data) {
    $original = $this->otherKey->fresh()->name;

    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'manage_private_key', ['action' => $action, 'uuid' => $this->otherKey->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(PrivateKey::find($this->otherKey->id))->not->toBeNull()
        ->and(PrivateKey::find($this->otherKey->id)->name)->toBe($original);
})->with([
    'get' => ['get', []],
    'update' => ['update', ['name' => 'Hijacked', 'private_key' => MCP_SRV_NEW_PRIVATE_KEY]],
    'delete' => ['delete', []],
]);

test('manage_private_key deletes an unused key of its own team', function () {
    $unused = PrivateKey::create(['team_id' => $this->team->id, 'name' => 'unused', 'private_key' => MCP_SRV_NEW_PRIVATE_KEY]);

    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_private_key', ['action' => 'delete', 'uuid' => $unused->uuid, 'confirm' => true]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(PrivateKey::find($unused->id))->toBeNull();
});

// ---------------------------------------------------------------- manage_s3_storage

test('manage_s3_storage creates a storage and never returns the secret', function () {
    $token = mcpSrvToken($this->user, ['root']);

    $create = mcpSrvCall($token, 'manage_s3_storage', ['action' => 'create', 'data' => [
        'name' => 'mcp-s3', 'endpoint' => 'https://s3.amazonaws.com', 'bucket' => 'mcp-bucket', 'region' => 'us-east-1',
        'key' => 'S3_ACCESS_KEY_VALUE', 'secret' => 'S3_SECRET_KEY_VALUE',
    ]]);
    expect(mcpSrvIsError($create))->toBeFalse(mcpSrvText($create))
        ->and(mcpSrvText($create))->not->toContain('S3_SECRET_KEY_VALUE');
    $storage = S3Storage::where('name', 'mcp-s3')->sole();
    expect($storage->team_id)->toBe($this->team->id);

    foreach ([['action' => 'list'], ['action' => 'get', 'uuid' => $storage->uuid]] as $arguments) {
        $read = mcpSrvCall($token, 'manage_s3_storage', $arguments);
        expect(mcpSrvIsError($read))->toBeFalse(mcpSrvText($read))
            ->and(mcpSrvText($read))->toContain($storage->uuid)
            ->and(mcpSrvText($read))->not->toContain('S3_SECRET_KEY_VALUE');
    }
});

test('manage_s3_storage cannot read, change, test, or delete a storage of another team', function (string $action, array $data) {
    $foreign = mcpSrvS3($this->otherTeam, ['name' => 'Theirs']);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'manage_s3_storage', ['action' => $action, 'uuid' => $foreign->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->not->toContain('S3_SECRET_KEY_VALUE')
        ->and($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Theirs');
})->with([
    'get' => ['get', []],
    'update' => ['update', ['name' => 'Hijacked']],
    'validate' => ['validate', []],
    'delete' => ['delete', []],
]);

test('manage_s3_storage list does not include storages of another team', function () {
    $foreign = mcpSrvS3($this->otherTeam);

    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_s3_storage', ['action' => 'list']);

    expect(mcpSrvIsError($response))->toBeFalse()
        ->and(mcpSrvText($response))->not->toContain($foreign->uuid);
});

// ---------------------------------------------------------------- manage_git_source

test('manage_git_source lists apps without client or webhook secrets', function () {
    $own = mcpSrvGithubApp($this->team, $this->key);
    $shared = mcpSrvGithubApp($this->otherTeam, $this->otherKey, ['name' => 'shared', 'is_system_wide' => true]);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'manage_git_source', ['action' => 'list', 'resource' => 'github']);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->toContain($own->uuid)
        ->and(mcpSrvText($response))->not->toContain('GH_CLIENT_SECRET_VALUE')
        ->and(mcpSrvText($response))->not->toContain('GH_WEBHOOK_SECRET_VALUE')
        ->and(mcpSrvText($response))->not->toContain('PRIVATE KEY');
});

test('manage_git_source rejects a GitHub app with a private key of another team', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_git_source', ['action' => 'create', 'resource' => 'github', 'data' => [
        'name' => 'stolen', 'html_url' => 'https://github.com', 'app_id' => 1, 'installation_id' => 2,
        'client_id' => 'id', 'client_secret' => 'secret', 'webhook_secret' => 'hook', 'private_key_uuid' => $this->otherKey->uuid,
    ]]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(GithubApp::where('name', 'stolen')->exists())->toBeFalse();

    $own = mcpSrvGithubApp($this->team, $this->key);
    $update = mcpSrvCall(mcpSrvToken($this->user), 'manage_git_source', ['action' => 'update', 'resource' => 'github', 'source_id' => (string) $own->id, 'data' => ['private_key_uuid' => $this->otherKey->uuid]]);
    expect(mcpSrvIsError($update))->toBeTrue()
        ->and($own->fresh()->private_key_id)->toBe($this->key->id);
});

test('manage_git_source cannot change or delete GitHub apps of another team, including system-wide ones', function (bool $systemWide, string $action) {
    $foreign = mcpSrvGithubApp($this->otherTeam, $this->otherKey, ['name' => 'Theirs', 'is_system_wide' => $systemWide]);

    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_git_source', ['action' => $action, 'resource' => 'github', 'source_id' => (string) $foreign->id, 'confirm' => true, 'data' => ['name' => 'Hijacked']]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Theirs');
})->with([
    'team app' => false,
    'system-wide app' => true,
])->with(['update', 'delete']);

test('manage_git_source creates, updates, and deletes a GitLab app without returning secrets', function () {
    $token = mcpSrvToken($this->user, ['read', 'write']);

    $create = mcpSrvCall($token, 'manage_git_source', ['action' => 'create', 'resource' => 'gitlab', 'data' => [
        'name' => 'mcp-gitlab', 'html_url' => 'https://gitlab.com', 'client_secret' => 'GL_CLIENT_SECRET_VALUE', 'webhook_token' => 'GL_WEBHOOK_TOKEN_VALUE',
    ]]);
    expect(mcpSrvIsError($create))->toBeFalse(mcpSrvText($create))
        ->and(mcpSrvText($create))->not->toContain('GL_CLIENT_SECRET_VALUE')
        ->and(mcpSrvText($create))->not->toContain('GL_WEBHOOK_TOKEN_VALUE');
    $app = GitlabApp::where('name', 'mcp-gitlab')->sole();
    expect($app->team_id)->toBe($this->team->id);

    $list = mcpSrvCall($token, 'manage_git_source', ['action' => 'list', 'resource' => 'gitlab']);
    expect(mcpSrvIsError($list))->toBeFalse()
        ->and(mcpSrvText($list))->not->toContain('GL_CLIENT_SECRET_VALUE')
        ->and(mcpSrvText($list))->not->toContain('GL_WEBHOOK_TOKEN_VALUE');
    $sourceId = collect(mcpSrvData($list))->firstWhere('name', 'mcp-gitlab')['id'];
    expect($sourceId)->not->toBeNull();

    $update = mcpSrvCall($token, 'manage_git_source', ['action' => 'update', 'resource' => 'gitlab', 'source_id' => (string) $sourceId, 'data' => ['name' => 'renamed-gitlab']]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and(mcpSrvText($update))->not->toContain('GL_CLIENT_SECRET_VALUE')
        ->and(mcpSrvText($update))->not->toContain('GL_WEBHOOK_TOKEN_VALUE')
        ->and($app->fresh()->name)->toBe('renamed-gitlab');

    $delete = mcpSrvCall($token, 'manage_git_source', ['action' => 'delete', 'resource' => 'gitlab', 'source_id' => (string) $sourceId, 'confirm' => true]);
    expect(mcpSrvIsError($delete))->toBeFalse(mcpSrvText($delete))
        ->and(GitlabApp::find($app->id))->toBeNull();
});

test('manage_git_source cannot change or delete a GitLab app of another team', function (string $action) {
    $foreign = GitlabApp::create(['name' => 'Theirs', 'html_url' => 'https://gitlab.com', 'api_url' => 'https://gitlab.com/api/v4', 'team_id' => $this->otherTeam->id]);

    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_git_source', ['action' => $action, 'resource' => 'gitlab', 'source_id' => (string) $foreign->id, 'confirm' => true, 'data' => ['name' => 'Hijacked']]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Theirs');
})->with(['update', 'delete']);

test('manage_git_source requires a known resource', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user), 'manage_git_source', ['action' => 'list', 'resource' => 'bitbucket']);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('resource must be one of: github, gitlab');
});

// ---------------------------------------------------------------- cloud_provider

test('cloud_provider adds and lists tokens without returning the token value', function () {
    Http::fake(['https://api.hetzner.cloud/v1/servers' => Http::response([], 200)]);
    $token = mcpSrvToken($this->user, ['root']);

    $create = mcpSrvCall($token, 'cloud_provider', ['action' => 'create_token', 'data' => ['provider' => 'hetzner', 'token' => 'HETZNER_TOKEN_VALUE', 'name' => 'mcp-hetzner']]);
    expect(mcpSrvIsError($create))->toBeFalse(mcpSrvText($create))
        ->and(mcpSrvText($create))->not->toContain('HETZNER_TOKEN_VALUE');
    $stored = CloudProviderToken::where('name', 'mcp-hetzner')->sole();
    expect($stored->team_id)->toBe($this->team->id);

    foreach ([['action' => 'list_tokens'], ['action' => 'get_token', 'uuid' => $stored->uuid]] as $arguments) {
        $read = mcpSrvCall($token, 'cloud_provider', $arguments);
        expect(mcpSrvIsError($read))->toBeFalse(mcpSrvText($read))
            ->and(mcpSrvText($read))->toContain($stored->uuid)
            ->and(mcpSrvText($read))->not->toContain('HETZNER_TOKEN_VALUE');
    }
});

test('cloud_provider cannot read, change, validate, or delete a token of another team', function (string $action, array $data) {
    Http::fake();
    $foreign = CloudProviderToken::create(['team_id' => $this->otherTeam->id, 'provider' => 'hetzner', 'token' => 'FOREIGN_TOKEN_VALUE', 'name' => 'Theirs']);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['root']), 'cloud_provider', ['action' => $action, 'uuid' => $foreign->uuid, 'confirm' => true, 'data' => $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->not->toContain('FOREIGN_TOKEN_VALUE')
        ->and($foreign->fresh())->not->toBeNull()
        ->and($foreign->fresh()->name)->toBe('Theirs');
    Http::assertNothingSent();
})->with([
    'get_token' => ['get_token', []],
    'update_token' => ['update_token', ['name' => 'Hijacked']],
    'validate_token' => ['validate_token', []],
    'delete_token' => ['delete_token', []],
]);

test('cloud_provider reads a catalog with a token of its own team', function () {
    Http::fake(['https://api.hetzner.cloud/v1/locations*' => Http::response([
        'locations' => [['id' => 1, 'name' => 'nbg1', 'description' => 'Nuremberg', 'country' => 'DE', 'city' => 'Nuremberg']],
        'meta' => ['pagination' => ['next_page' => null]],
    ], 200)]);
    $cloudToken = CloudProviderToken::create(['team_id' => $this->team->id, 'provider' => 'hetzner', 'token' => 'OWN_TOKEN_VALUE', 'name' => 'Mine']);

    $response = mcpSrvCall(mcpSrvToken($this->user), 'cloud_provider', ['action' => 'catalog', 'resource' => 'hetzner_locations', 'data' => ['cloud_provider_token_uuid' => $cloudToken->uuid]]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->toContain('nbg1')
        ->and(mcpSrvText($response))->not->toContain('OWN_TOKEN_VALUE');
});

test('cloud_provider cannot use a token of another team for catalogs or provisioning', function (string $action, string $resource, array $data) {
    Http::fake();
    $foreign = CloudProviderToken::create(['team_id' => $this->otherTeam->id, 'provider' => 'hetzner', 'token' => 'FOREIGN_TOKEN_VALUE', 'name' => 'Theirs']);
    $serverCount = Server::count();

    $response = mcpSrvCall(mcpSrvToken($this->user), 'cloud_provider', ['action' => $action, 'resource' => $resource, 'confirm' => true, 'data' => ['cloud_provider_token_uuid' => $foreign->uuid] + $data]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(Server::count())->toBe($serverCount);
    Http::assertNothingSent();
})->with([
    'catalog' => ['catalog', 'hetzner_server_types', []],
    'provision' => ['provision_server', 'hetzner', ['location' => 'nbg1', 'server_type' => 'cx22', 'image' => 1, 'name' => 'evil']],
]);

test('cloud_provider provisioning rejects a private key of another team', function () {
    Http::fake();
    $cloudToken = CloudProviderToken::create(['team_id' => $this->team->id, 'provider' => 'hetzner', 'token' => 'OWN_TOKEN_VALUE', 'name' => 'Mine']);
    $serverCount = Server::count();

    $response = mcpSrvCall(mcpSrvToken($this->user), 'cloud_provider', ['action' => 'provision_server', 'resource' => 'hetzner', 'confirm' => true, 'data' => [
        'cloud_provider_token_uuid' => $cloudToken->uuid, 'private_key_uuid' => $this->otherKey->uuid,
        'location' => 'nbg1', 'server_type' => 'cx22', 'image' => 1, 'name' => 'evil',
    ]]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(Server::count())->toBe($serverCount);
    Http::assertNothingSent();
});

test('cloud_provider provisioning a Hostinger VPS with a post-install script or extra SSH keys needs deploy', function (array $extra) {
    Http::fake();
    $cloudToken = CloudProviderToken::create(['team_id' => $this->team->id, 'provider' => 'hostinger', 'token' => 'OWN_TOKEN_VALUE', 'name' => 'Mine']);
    $serverCount = Server::count();

    $response = mcpSrvCall(mcpSrvToken($this->user), 'cloud_provider', ['action' => 'provision_server', 'resource' => 'hostinger', 'confirm' => true, 'data' => [
        'cloud_provider_token_uuid' => $cloudToken->uuid, 'private_key_uuid' => $this->key->uuid,
        'item_id' => 'hostingercom-vps-kvm1-usd-1m', 'data_center_id' => 19, 'template_id' => 1130,
        ...$extra,
    ]]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('Missing required permissions: deploy')
        ->and(Server::count())->toBe($serverCount);
    Http::assertNothingSent();
})->with([
    'post-install script' => [['post_install_script_id' => 73]],
    'extra SSH keys' => [['public_key_ids' => [42]]],
]);

// ---------------------------------------------------------------- manage_notifications

test('manage_notifications updates a channel of the token team and never returns webhook URLs or tokens', function () {
    $token = mcpSrvToken($this->user, ['root']);
    $otherSettings = DiscordNotificationSettings::query()->firstOrCreate(['team_id' => $this->otherTeam->id]);

    $update = mcpSrvCall($token, 'manage_notifications', ['action' => 'update', 'resource' => 'discord', 'data' => [
        'discord_enabled' => true, 'discord_webhook_url' => 'https://discord.com/api/webhooks/123/DISCORD_WEBHOOK_SECRET',
    ]]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and(mcpSrvText($update))->not->toContain('DISCORD_WEBHOOK_SECRET');

    expect(DiscordNotificationSettings::where('team_id', $this->team->id)->sole()->discord_webhook_url)->toContain('DISCORD_WEBHOOK_SECRET')
        ->and($otherSettings->fresh()->discord_webhook_url)->toBeNull();

    $get = mcpSrvCall($token, 'manage_notifications', ['action' => 'get', 'resource' => 'discord']);
    expect(mcpSrvIsError($get))->toBeFalse(mcpSrvText($get))
        ->and(mcpSrvText($get))->toContain('discord_enabled')
        ->and(mcpSrvText($get))->not->toContain('DISCORD_WEBHOOK_SECRET');
});

test('manage_notifications never returns channel secrets', function (string $channel, array $data, string $secret) {
    $token = mcpSrvToken($this->user, ['root']);

    $update = mcpSrvCall($token, 'manage_notifications', ['action' => 'update', 'resource' => $channel, 'data' => $data]);
    expect(mcpSrvIsError($update))->toBeFalse(mcpSrvText($update))
        ->and(mcpSrvText($update))->not->toContain($secret);

    $get = mcpSrvCall($token, 'manage_notifications', ['action' => 'get', 'resource' => $channel]);
    expect(mcpSrvIsError($get))->toBeFalse(mcpSrvText($get))
        ->and(mcpSrvText($get))->not->toContain($secret);
})->with([
    'email smtp password' => ['email', ['smtp_password' => 'SMTP_PASSWORD_VALUE', 'resend_api_key' => 'RESEND_KEY_VALUE'], 'SMTP_PASSWORD_VALUE'],
    'email resend key' => ['email', ['resend_api_key' => 'RESEND_KEY_VALUE'], 'RESEND_KEY_VALUE'],
    'slack webhook' => ['slack', ['slack_webhook_url' => 'https://hooks.slack.com/services/SLACK_SECRET_PATH'], 'SLACK_SECRET_PATH'],
    'telegram token' => ['telegram', ['telegram_token' => 'TELEGRAM_TOKEN_VALUE', 'telegram_chat_id' => '123'], 'TELEGRAM_TOKEN_VALUE'],
    'pushover keys' => ['pushover', ['pushover_user_key' => 'PUSHOVER_USER_VALUE', 'pushover_api_token' => 'PUSHOVER_TOKEN_VALUE'], 'PUSHOVER_'],
    'webhook url' => ['webhook', ['webhook_url' => 'https://example.com/hook/WEBHOOK_SECRET_PATH'], 'WEBHOOK_SECRET_PATH'],
]);

test('manage_notifications only changes the token team', function () {
    TelegramNotificationSettings::query()->firstOrCreate(['team_id' => $this->otherTeam->id]);
    $sessionSwitch = Team::factory()->create();
    $sessionSwitch->members()->attach($this->user->id, ['role' => 'owner']);
    $token = mcpSrvToken($this->user);
    session(['currentTeam' => $sessionSwitch]);

    $response = mcpSrvCall($token, 'manage_notifications', ['action' => 'update', 'resource' => 'telegram', 'data' => ['telegram_chat_id' => '999']]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(TelegramNotificationSettings::where('team_id', $this->team->id)->sole()->telegram_chat_id)->toBe('999')
        ->and(TelegramNotificationSettings::where('team_id', $this->otherTeam->id)->sole()->telegram_chat_id)->toBeNull()
        ->and(TelegramNotificationSettings::where('team_id', $sessionSwitch->id)->first()?->telegram_chat_id)->toBeNull();
});

// ---------------------------------------------------------------- list_audit_events

test('list_audit_events returns only events of the token team', function () {
    AuditEvent::factory()->create(['team_id' => $this->team->id, 'description' => 'own-team-event']);
    AuditEvent::factory()->create(['team_id' => $this->otherTeam->id, 'description' => 'other-team-event']);

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read']), 'list_audit_events', ['action' => 'list', 'data' => ['per_page' => 50]]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response))
        ->and(mcpSrvText($response))->toContain('own-team-event')
        ->and(mcpSrvText($response))->not->toContain('other-team-event');
});

test('list_audit_events is denied for team members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    AuditEvent::factory()->create(['team_id' => $this->team->id, 'description' => 'own-team-event']);

    $response = mcpSrvCall(mcpSrvToken($member, ['read']), 'list_audit_events', ['action' => 'list']);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->not->toContain('own-team-event');
});

// ---------------------------------------------------------------- get_server_metrics

test('get_server_metrics returns recent metrics for a server of the token team', function () {
    McpSrvStubMetricsClient::$calls = 0;
    $this->app->bind(SentinelMetricsClient::class, fn ($app, array $parameters) => new McpSrvStubMetricsClient($parameters['server']));
    $this->server->settings->update(['is_metrics_enabled' => true]);
    Server::flushIdentityMap();

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read']), 'get_server_metrics', ['server_uuid' => $this->server->uuid, 'minutes' => 60]);

    expect(mcpSrvIsError($response))->toBeFalse(mcpSrvText($response));
    $data = mcpSrvData($response);
    expect($data['online'])->toBeTrue()
        ->and($data['current']['cpu_percent'])->toBe(42.5)
        ->and($data['series']['cpu_percent'])->toHaveCount(1)
        ->and($data['series']['disk_used_percent'])->toBe([])
        ->and($data['series']['network_rx_bytes_per_sec'])->toHaveCount(1)
        ->and(mcpSrvText($response))->not->toContain('sentinel_token');
});

test('get_server_metrics caps minutes and rejects invalid values', function () {
    $this->app->bind(SentinelMetricsClient::class, fn ($app, array $parameters) => new McpSrvStubMetricsClient($parameters['server']));
    $this->server->settings->update(['is_metrics_enabled' => true]);
    Server::flushIdentityMap();
    $token = mcpSrvToken($this->user, ['read']);

    $capped = mcpSrvCall($token, 'get_server_metrics', ['server_uuid' => $this->server->uuid, 'minutes' => 999999]);
    expect(mcpSrvData($capped)['minutes'])->toBe(43200);

    $invalid = mcpSrvCall($token, 'get_server_metrics', ['server_uuid' => $this->server->uuid, 'minutes' => 0]);
    expect(mcpSrvIsError($invalid))->toBeTrue();
});

test('get_server_metrics does not read metrics of a server of another team', function () {
    McpSrvStubMetricsClient::$calls = 0;
    $this->app->bind(SentinelMetricsClient::class, fn ($app, array $parameters) => new McpSrvStubMetricsClient($parameters['server']));
    $this->otherServer->settings->update(['is_metrics_enabled' => true]);
    Server::flushIdentityMap();

    $response = mcpSrvCall(mcpSrvToken($this->user, ['read']), 'get_server_metrics', ['server_uuid' => $this->otherServer->uuid]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('not found')
        ->and(McpSrvStubMetricsClient::$calls)->toBe(0);
});

test('get_server_metrics explains when metrics are disabled', function () {
    $response = mcpSrvCall(mcpSrvToken($this->user, ['read']), 'get_server_metrics', ['server_uuid' => $this->server->uuid]);

    expect(mcpSrvIsError($response))->toBeTrue()
        ->and(mcpSrvText($response))->toContain('Metrics are not enabled');
});
