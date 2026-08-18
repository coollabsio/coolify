<?php

use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('returns fresh v5 cluster bootstrap state for realtime fallback refreshes', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrap_action' => 'bootstrap',
        'last_bootstrap_status' => 'running',
        'last_bootstrap_output' => 'Starting Coolify CLI bootstrap for prod-01...',
        'last_bootstrap_ran_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.id', $cluster->uuid)
        ->assertJsonPath('cluster.servers.0.id', $server->uuid)
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'running')
        ->assertJsonPath('cluster.servers.0.lastBootstrapOutput', 'Starting Coolify CLI bootstrap for prod-01...');
});

it('shows v5 clusters with their servers on the cluster page', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/clusters')
        ->assertSuccessful()
        ->assertSee('Clusters', false)
        ->assertSee('"clusters":[', false)
        ->assertSee('"privateKeys":[', false)
        ->assertSee('"name":"Lima Key"', false)
        ->assertSee('"name":"Development-Lima"', false)
        ->assertSee('"serversCount":1', false)
        ->assertSee('"name":"coold-dev"', false)
        ->assertSee('"host":"lima-coold-dev"', false)
        ->assertDontSee('"sshUser"', false)
        ->assertDontSee('"sshPort"', false)
        ->assertSee('"builderEnabled":true', false)
        ->assertSee('"builderCapacity":2', false)
        ->assertSee('"wireguardInterface":"wg0"', false)
        ->assertSee('"wireguardManagementPool":"100.64.0.0\\/16"', false)
        ->assertSee('"containerNetworkPool":"10.210.0.0\\/16"', false)
        ->assertSee('"namespaces":["default"]', false)
        ->assertSee('"defaultDenyContainers":true', false)
        ->assertSee('"cooldVersion":"nightly"', false)
        ->assertSee('"corrosionVersion":"v1.0.0"', false)
        ->assertSee('"builderCpuQuota":"200%"', false)
        ->assertSee('"builderMemoryMax":"2G"', false)
        ->assertSee('"builderTimeoutSecs":1800', false)
        ->assertSee('"lastCliStatus":null', false)
        ->assertSee('"privateKeyName":"Lima Key"', false)
        ->assertSee('"nodeAddress":null', false)
        ->assertSee('"wireguardManagementIp":null', false)
        ->assertSee('"containerSubnets":[]', false)
        ->assertSee('"lastBootstrappedAt":"', false);
});

it('creates a v5 cluster for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => 'Primary production cluster.',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.name', 'Production Mesh')
        ->assertJsonPath('cluster.description', 'Primary production cluster.')
        ->assertJsonPath('cluster.wireguardInterface', 'wg0')
        ->assertJsonPath('cluster.wireguardManagementPool', '100.64.0.0/16')
        ->assertJsonPath('cluster.wireguardListenPort', 51820)
        ->assertJsonPath('cluster.containerNetworkPool', '10.210.0.0/16')
        ->assertJsonPath('cluster.containerNetworkPrefix', 24)
        ->assertJsonPath('cluster.namespaces', ['default'])
        ->assertJsonPath('cluster.defaultDenyContainers', true)
        ->assertJsonPath('cluster.cooldVersion', 'nightly')
        ->assertJsonPath('cluster.corrosionVersion', 'v1.0.0')
        ->assertJsonPath('cluster.corrosionGossipPort', 8787)
        ->assertJsonPath('cluster.corrosionApiPort', 8080)
        ->assertJsonPath('cluster.builderEnabled', true)
        ->assertJsonPath('cluster.builderCapacity', 2)
        ->assertJsonPath('cluster.builderCpuQuota', '200%')
        ->assertJsonPath('cluster.builderMemoryMax', '2G')
        ->assertJsonPath('cluster.builderTimeoutSecs', 1800)
        ->assertJsonPath('cluster.lastCliAction', null)
        ->assertJsonPath('cluster.lastCliStatus', null)
        ->assertJsonPath('cluster.lastCliSummary', null)
        ->assertJsonPath('cluster.lastCliRanAt', null)
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(Cluster::query()
        ->where('team_id', $team->id)
        ->where('created_by_user_id', $user->id)
        ->where('name', 'Production Mesh')
        ->where('description', 'Primary production cluster.')
        ->where('wireguard_interface', 'wg0')
        ->where('wireguard_management_pool', '100.64.0.0/16')
        ->where('container_network_pool', '10.210.0.0/16')
        ->exists())->toBeTrue();
});

it('creates a v5 cluster with advanced cli configuration', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Custom Mesh',
            'description' => null,
            'wireguard_interface' => 'wg-prod',
            'wireguard_management_pool' => '100.65.0.0/16',
            'wireguard_listen_port' => 51830,
            'container_network_pool' => '10.211.0.0/16',
            'container_network_prefix' => 25,
            'namespaces' => ['default', 'preview'],
            'default_deny_containers' => false,
            'coold_version' => 'v0.2.0',
            'corrosion_version' => 'v1.1.0',
            'corrosion_gossip_port' => 8788,
            'corrosion_api_port' => 8081,
            'builder_enabled' => true,
            'builder_capacity' => 4,
            'builder_cpu_quota' => '400%',
            'builder_memory_max' => '4G',
            'builder_timeout_secs' => 2400,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.wireguardInterface', 'wg-prod')
        ->assertJsonPath('cluster.wireguardManagementPool', '100.65.0.0/16')
        ->assertJsonPath('cluster.wireguardListenPort', 51830)
        ->assertJsonPath('cluster.containerNetworkPool', '10.211.0.0/16')
        ->assertJsonPath('cluster.containerNetworkPrefix', 25)
        ->assertJsonPath('cluster.namespaces', ['default', 'preview'])
        ->assertJsonPath('cluster.defaultDenyContainers', false)
        ->assertJsonPath('cluster.cooldVersion', 'v0.2.0')
        ->assertJsonPath('cluster.corrosionVersion', 'v1.1.0')
        ->assertJsonPath('cluster.builderCapacity', 4)
        ->assertJsonPath('cluster.builderCpuQuota', '400%')
        ->assertJsonPath('cluster.builderMemoryMax', '4G')
        ->assertJsonPath('cluster.builderTimeoutSecs', 2400);
});

it('validates advanced v5 cluster cli configuration', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Broken Mesh',
            'wireguard_management_pool' => 'not-a-cidr',
            'container_network_pool' => '10.211.0.0',
            'wireguard_listen_port' => 70000,
            'namespaces' => ['Default'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'wireguard_management_pool',
            'container_network_pool',
            'wireguard_listen_port',
            'namespaces.0',
        ]);
});

it('requires positive v5 cluster builder capacity when builders are enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Zero Builder Mesh',
            'builder_enabled' => true,
            'builder_capacity' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});

it('validates v5 cluster creation input', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => '',
            'description' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'description']);
});

it('rejects duplicate v5 cluster names in the same team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('deletes an empty v5 cluster in the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Empty Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->uuid)
        ->assertNoContent();

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeFalse();
});

it('does not delete a v5 cluster that has servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => null,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->uuid)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only empty clusters can be deleted.');

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeTrue();
});

it('does not delete a v5 cluster outside the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherUser = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Other User',
        'email' => 'other-delete@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $cluster = Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'Other Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson('/v5/clusters/'.$cluster->uuid)
        ->assertNotFound();

    expect(Cluster::query()->whereKey($cluster->id)->exists())->toBeTrue();
});

it('allows the same v5 cluster name in another team without leaking it', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherUser = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $otherUser->id,
        'name' => 'Production Mesh',
        'description' => 'Other team cluster.',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/clusters', [
            'name' => 'Production Mesh',
            'description' => 'Current team cluster.',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.description', 'Current team cluster.');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5/clusters')
        ->assertSuccessful()
        ->assertSee('Current team cluster.')
        ->assertDontSee('Other team cluster.');
});
