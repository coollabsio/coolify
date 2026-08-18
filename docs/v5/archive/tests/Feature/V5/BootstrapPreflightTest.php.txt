<?php

use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Jobs\V5BootstrapServerJob;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');
    Schema::dropIfExists('private_keys');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
});

function createBootstrapPreflightTables(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->default('Anonymous');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('description')->nullable();
        $table->boolean('personal_team')->default(false);
        $table->boolean('show_boarding')->default(false);
        $table->timestamps();
    });

    Schema::create('team_user', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('user_id');
        $table->string('role')->default('member');
        $table->timestamps();

        $table->unique(['team_id', 'user_id']);
    });

    Schema::create('private_keys', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('name');
        $table->string('description')->nullable();
        $table->longText('private_key');
        $table->string('fingerprint')->nullable();
        $table->boolean('is_git_related')->default(false);
        $table->foreignId('team_id');
        $table->timestamps();
    });

    V5TestSchema::createClustersTable();
    V5TestSchema::createServersTable();
}

/**
 * @return array{0: User, 1: Team}
 */
function createBootstrapPreflightUserWithTeam(): array
{
    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => 'margaret@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Tooling Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    return [$user, $team];
}

function createBootstrapPreflightPrivateKey(Team $team): PrivateKey
{
    return PrivateKey::withoutEvents(fn () => PrivateKey::query()->forceCreate([
        'uuid' => 'preflight-key-uuid',
        'name' => 'Preflight SSH Key',
        'description' => null,
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
        'fingerprint' => 'preflight-key',
        'is_git_related' => false,
        'team_id' => $team->id,
    ]));
}

/**
 * @return array{0: User, 1: Team, 2: Cluster, 3: V5Server}
 */
function createBootstrapPreflightClusterWithServer(array $clusterAttributes = [], array $serverAttributes = []): array
{
    [$user, $team] = createBootstrapPreflightUserWithTeam();
    $privateKey = createBootstrapPreflightPrivateKey($team);
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        ...$clusterAttributes,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        ...$serverAttributes,
    ]);

    return [$user, $team, $cluster, $server];
}

it('rejects bootstrap when flux url is not configured', function () {
    createBootstrapPreflightTables();
    Config::set('coold.flux_url', '');

    [$user, $team, $cluster, $server] = createBootstrapPreflightClusterWithServer();

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'COOLIFY_COOLD_FLUX_URL is not configured, so bootstrapped servers cannot be enrolled into Flux. Set it and retry the bootstrap.');

    Queue::assertNothingPushed();

    // The claim must not have been written either.
    expect($server->refresh()->last_bootstrap_status)->toBeNull();
});

it('queues the bootstrap when the flux url is configured', function () {
    createBootstrapPreflightTables();
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');

    [$user, $team, $cluster, $server] = createBootstrapPreflightClusterWithServer();

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('message', 'Bootstrap queued.');

    Queue::assertPushed(V5BootstrapServerJob::class);
});

it('writes the marker with the same coold version that is persisted on the server row', function () {
    createBootstrapPreflightTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');

    [, , $cluster, $server] = createBootstrapPreflightClusterWithServer([
        'namespaces' => ['default'],
        'coold_version' => 'v0.9.9',
    ], [
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    $issuer = Mockery::mock(AgentTokenIssuer::class);
    $issuer->shouldReceive('issueForServer')->andReturn('signed-host-jwt');
    app()->instance(AgentTokenIssuer::class, $issuer);

    // The CLI reports a newer coold version than the cluster asked for; the
    // marker and the database row must both use the reported version.
    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: ''))
            ->push(Process::result(output: json_encode([
                'results' => [],
                'coold_version' => 'v1.2.3',
                'verified' => [[
                    'host' => "v5-server-{$server->uuid}",
                    'wireguard_ip' => '100.64.0.1',
                    'peer_count' => 0,
                    'status' => 'ok',
                ]],
            ], JSON_THROW_ON_ERROR)."\n"))
            ->push(Process::result(output: "public-key\n"))
            ->push(Process::result(output: "default=10.210.0.0/24\n"))
            ->push(Process::result(output: "marker written\n"))
            ->push(Process::result(output: "coold restarted\n")),
    ]);

    Event::fake([V5ClusterUpdated::class, V5CanvasResourceUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->status)->toBe('installed')
        ->and($server->coold_version)->toBe('v1.2.3');

    $markerPayload = null;

    Process::assertRan(function ($process) use (&$markerPayload): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        if (! str_contains($command, 'v5-node.json') || ! str_contains($command, 'base64 -d')) {
            return false;
        }

        if (preg_match("/payload='([^']+)'/", $command, $matches) === 1) {
            $markerPayload = json_decode(base64_decode($matches[1], true), true);
        }

        return true;
    });

    expect($markerPayload)->toBeArray()
        ->and($markerPayload['coold_version'])->toBe('v1.2.3')
        ->and($markerPayload['coold_version'])->toBe($server->coold_version)
        ->and($markerPayload['server_uuid'])->toBe($server->uuid)
        ->and($markerPayload['wireguard_management_ip'])->toBe('100.64.0.1');
});
