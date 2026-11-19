<?php

use App\Models\Team;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ApplicationDomain as V5ApplicationDomain;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('updates v5 caddy ingress canvas position for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['ingress'],
        'canvas_x' => -352,
        'canvas_y' => 0,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/caddy-ingresses/{$server->uuid}/position", [
            'canvas_x' => -160,
            'canvas_y' => 240,
        ])
        ->assertSuccessful()
        ->assertJsonPath('caddyIngress.canvasX', -160)
        ->assertJsonPath('caddyIngress.canvasY', 240);

    expect($server->refresh()->canvas_x)->toBe(-160)
        ->and($server->canvas_y)->toBe(240);
});

it('restarts v5 server coold over ssh with a freshly minted host jwt', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'unreachable',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(AgentTokenIssuer::class, function (MockInterface $mock) use ($server): void {
        $mock->shouldReceive('issueForServer')
            ->once()
            ->with(Mockery::on(fn (V5Server $subject): bool => $subject->is($server)))
            ->andReturn('fresh-host-jwt');
    });

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cooldLogs')
            ->once()
            ->with(Mockery::type('string'), 1)
            ->andReturn('coold restarted');
    });

    Process::fake([
        '*' => Process::result(output: "active\n● coold.service - Coolify host agent\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/restart-coold")
        ->assertSuccessful()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('output', "active\n● coold.service - Coolify host agent")
        ->assertJsonStructure(['cluster', 'output', 'connected', 'restartedAt']);

    expect($server->refresh()->status)->toBe('installed')
        ->and($server->last_status_check)->toBe('flux')
        ->and($server->last_status_output)->toBe('coold restarted over SSH and reconnected to Flux.');

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, '203.0.113.10')
            && str_contains($command, base64_encode('fresh-host-jwt'))
            && str_contains($command, '/etc/coolify/host-jwt')
            && str_contains($command, 'systemctl restart coold.service');
    });
});

it('requires a private key before restarting v5 server coold over ssh', function () {
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
        'status' => 'unreachable',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    Process::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/restart-coold")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No private key is attached to this server.');
});

it('fetches v5 server coold logs through flux', function () {
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
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cooldLogs')
            ->once()
            ->with(Mockery::type('string'), 200)
            ->andReturn('Jun 22 coold[123]: started');
    });

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/coold-logs?tail=200")
        ->assertSuccessful()
        ->assertJsonPath('output', 'Jun 22 coold[123]: started')
        ->assertJsonStructure(['output', 'fetchedAt']);
});

it('falls back to ssh for v5 server coold logs when the host is not connected to flux', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cooldLogs')
            ->once()
            ->with(Mockery::type('string'), 50)
            ->andThrow(new RuntimeException('host is not connected'));
    });

    Process::fake([
        '*' => Process::result(output: "Jul 06 coold[123]: reconnecting\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/coold-logs?tail=50")
        ->assertSuccessful()
        ->assertJsonPath('output', 'Jul 06 coold[123]: reconnecting')
        ->assertJsonPath('source', 'ssh')
        ->assertJsonStructure(['output', 'source', 'fetchedAt']);

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'sudo -n journalctl -u coold -n 50 --no-pager -q')
            && str_contains($command, '203.0.113.10')
            && in_array('LogLevel=ERROR', $process->command, true);
    });
});

it('falls back to ssh for v5 server coold logs when flux itself is unavailable', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cooldLogs')
            ->once()
            ->with(Mockery::type('string'), 200)
            ->andThrow(new RuntimeException('Flux socket was not found.'));
    });

    Process::fake([
        '*' => Process::result(output: "Jul 06 coold[123]: no flux socket\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/coold-logs")
        ->assertSuccessful()
        ->assertJsonPath('output', 'Jul 06 coold[123]: no flux socket')
        ->assertJsonPath('source', 'ssh');
});

it('fetches v5 server corrosion tables through flux', function () {
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
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('corrosionTables')
            ->once()
            ->with(Mockery::type('string'), 200)
            ->andReturn('{"limit":200,"tables":[{"name":"service_endpoints","columns":["container_name"],"rows":[["coolify-v5-nginx"]]}]}');
    });

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/corrosion-tables?limit=200")
        ->assertSuccessful()
        ->assertJsonPath('output', '{"limit":200,"tables":[{"name":"service_endpoints","columns":["container_name"],"rows":[["coolify-v5-nginx"]]}]}')
        ->assertJsonStructure(['output', 'fetchedAt']);
});

it('falls back to ssh for v5 server corrosion tables when flux fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('corrosionTables')
            ->once()
            ->with(Mockery::type('string'), 200)
            ->andThrow(new RuntimeException('host not connected'));
    });

    Process::fake([
        '*' => Process::result(output: '{"limit":200,"tables":[{"name":"service_endpoints","columns":["container_name"],"rows":[["coolify-v5-nginx"]]}]}'),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/corrosion-tables?limit=200")
        ->assertSuccessful()
        ->assertJsonPath('output', '{"limit":200,"tables":[{"name":"service_endpoints","columns":["container_name"],"rows":[["coolify-v5-nginx"]]}]}')
        ->assertJsonPath('source', 'ssh');

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, '127.0.0.1:8080/v1/queries')
            && str_contains($command, 'sqlite_schema')
            && str_contains($command, '203.0.113.10')
            && in_array('LogLevel=ERROR', $process->command, true);
    });
});

it('fetches v5 server firewall rules through flux', function () {
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
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listFirewallRules')
            ->once()
            ->with(Mockery::type('string'), '')
            ->andReturn([[
                'id' => 'v5-resource-connection:1:1:2:tcp:5432',
                'namespace' => 'default',
                'src' => 'coolify-v5-api',
                'dst' => 'coolify-v5-postgres',
                'proto' => 'tcp',
                'port' => 5432,
            ]]);
    });

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/firewall-rules")
        ->assertSuccessful()
        ->assertJsonPath('rules.0.id', 'v5-resource-connection:1:1:2:tcp:5432')
        ->assertJsonPath('rules.0.port', 5432)
        ->assertJsonStructure(['rules', 'fetchedAt']);
});

it('falls back to ssh for v5 server firewall rules when flux fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'wireguard_management_ip' => '100.64.0.10',
        'node_address' => '203.0.113.10',
    ]);

    $this->mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listFirewallRules')
            ->once()
            ->with(Mockery::type('string'), '')
            ->andThrow(new RuntimeException('host is not connected'));
    });

    Process::fake([
        '*' => Process::result(output: '[{"id":"v5-resource-connection:1:1:2:tcp:5432","namespace":"default","src":"coolify-v5-api","dst":"coolify-v5-postgres","proto":"tcp","port":5432}]'),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/firewall-rules")
        ->assertSuccessful()
        ->assertJsonPath('rules.0.id', 'v5-resource-connection:1:1:2:tcp:5432')
        ->assertJsonPath('rules.0.port', 5432)
        ->assertJsonPath('source', 'ssh');

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, '/etc/coolify/firewall-rules.tsv')
            && str_contains($command, '203.0.113.10')
            && in_array('LogLevel=ERROR', $process->command, true);
    });
});

it('adds a v5 server to a cluster for the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'builder_enabled' => true,
        'builder_capacity' => 3,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'node_address' => '203.0.113.10',
            'builder_enabled' => true,
            'builder_capacity' => 3,
            'wireguard_listen_port_override' => 51821,
            'wireguard_endpoint_override' => 'prod-01.example.com:51821',
            'coold_version' => 'v9.9.9',
            'corrosion_version' => 'v8.8.8',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.serversCount', 1)
        ->assertJsonPath('cluster.servers.0.name', 'prod-01')
        ->assertJsonPath('cluster.servers.0.host', '203.0.113.10')
        ->assertJsonMissingPath('cluster.servers.0.sshUser')
        ->assertJsonMissingPath('cluster.servers.0.sshPort')
        ->assertJsonMissingPath('cluster.servers.0.cooldVersion')
        ->assertJsonMissingPath('cluster.servers.0.corrosionVersion')
        ->assertJsonPath('cluster.servers.0.privateKeyName', 'Production SSH Key')
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.nodeAddress', '203.0.113.10')
        ->assertJsonPath('cluster.servers.0.builderEnabled', true)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 3)
        ->assertJsonPath('cluster.servers.0.builderCpuQuota', '200%')
        ->assertJsonPath('cluster.servers.0.ingressEnabled', false)
        ->assertJsonPath('cluster.servers.0.ingressType', null)
        ->assertJsonPath('cluster.servers.0.capabilities', [])
        ->assertJsonPath('cluster.servers.0.wireguardListenPortOverride', 51821)
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'prod-01.example.com:51821')
        ->assertJsonPath('cluster.servers.0.wireguardManagementIp', null)
        ->assertJsonPath('cluster.servers.0.containerSubnets', []);

    expect(V5Server::query()
        ->where('team_id', $team->id)
        ->where('cluster_id', $cluster->id)
        ->where('created_by_user_id', $user->id)
        ->where('name', 'prod-01')
        ->where('host', '203.0.113.10')
        ->where('ssh_user', 'root')
        ->where('ssh_port', 22)
        ->where('private_key_id', $privateKey->id)
        ->where('node_address', '203.0.113.10')
        ->exists())->toBeTrue();

    expect(V5Server::query()->where('name', 'prod-01')->first()->capabilities)
        ->toBe([]);
});

it('adds a v5 server with caddy ingress enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'edge-01',
            'host' => '203.0.113.20',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy')
        ->assertJsonPath('cluster.servers.0.capabilities', ['ingress']);

    $server = V5Server::query()->where('name', 'edge-01')->first();

    expect($server->capabilities)->toBe(['ingress'])
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->isIngress())->toBeTrue();
});

it('keeps added v5 server builder capacity when builder is disabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'builder_enabled' => true,
        'builder_capacity' => 3,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'builder_enabled' => false,
            'builder_capacity' => 3,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 3)
        ->assertJsonPath('cluster.servers.0.capabilities', []);

    $server = V5Server::query()->where('name', 'prod-01')->first();

    expect($server->builder_enabled)->toBeFalse()
        ->and($server->builder_capacity)->toBe(3)
        ->and($server->capabilities)->toBe([]);
});

it('requires positive v5 server builder capacity when builder is enabled', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'builder_enabled' => true,
            'builder_capacity' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});

it('defaults dev Lima wireguard overrides for host docker internal servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Testing Host Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'coolify-naked-test',
            'host' => 'host.docker.internal',
            'ssh_user' => 'root',
            'ssh_port' => 60003,
            'private_key_uuid' => $privateKey->uuid,
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.wireguardListenPortOverride', 51823)
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'host.lima.internal:51823');
});

it('rejects adding a v5 server to another teams cluster', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $cluster = Cluster::query()->create([
        'team_id' => $otherTeam->id,
        'created_by_user_id' => $user->id,
        'name' => 'Other Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
        ])
        ->assertForbidden();
});

it('validates v5 server creation input', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => '',
            'host' => '',
            'ssh_user' => '',
            'ssh_port' => 70000,
            'private_key_uuid' => null,
            'wireguard_listen_port_override' => 70000,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
            'host',
            'ssh_user',
            'ssh_port',
            'private_key_uuid',
            'wireguard_listen_port_override',
        ]);
});

it('rejects unsafe v5 server host and wireguard endpoint input', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => "203.0.113.10\nProxyCommand sh -c whoami",
            'ssh_user' => "root\nUser attacker",
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'node_address' => "100.64.0.10\nHost *",
            'wireguard_endpoint_override' => 'not a host port',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'host',
            'ssh_user',
            'node_address',
            'wireguard_endpoint_override',
        ]);
});

it('accepts bracketed IPv6 wireguard endpoint overrides for v5 servers', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-ipv6',
            'host' => '2001:db8::10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'wireguard_endpoint_override' => '[2001:db8::10]:51820',
        ])
        ->assertCreated()
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', '[2001:db8::10]:51820');
});

it('rejects private keys from another team when adding a v5 server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $otherPrivateKey = createV5PrivateKey($otherTeam, 'Other SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-01',
            'host' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $otherPrivateKey->uuid,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['private_key_uuid']);
});

it('checks v5 server ssh status without storing diagnostic output', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
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
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Process::fake([
        '*' => Process::result(output: "SSH connection OK\nprod-01\nLinux aarch64\n/usr/bin/docker\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/check")
        ->assertSuccessful()
        ->assertJsonPath('status', 'reachable')
        ->assertJsonPath('output', "SSH connection OK\nprod-01\nLinux aarch64\n/usr/bin/docker")
        ->assertJsonPath('checkedAt', fn (?string $value) => $value !== null);

    Process::assertRan(fn ($process) => str_contains(json_encode($process->command), '203.0.113.10'));
    Process::assertRan(fn ($process) => in_array('LogLevel=ERROR', $process->command, true));

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_status_check)->toBeNull()
        ->and($server->last_status_output)->toBeNull()
        ->and($server->last_status_checked_at)->toBeNull();
});

it('blocks starting a second v5 server bootstrap while another cluster bootstrap is active', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrap_status' => 'running',
        'last_bootstrap_ran_at' => now()->subMinutes(5),
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-02',
        'host' => '203.0.113.11',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertConflict()
        ->assertJsonPath('message', 'Another server bootstrap is already queued or running for this cluster.');

    Queue::assertNothingPushed();
    expect($server->refresh()->last_bootstrap_status)->toBeNull();
});

it('deletes an unbootstrapped v5 server from a cluster', function () {
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
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('deletes a bootstrapped v5 server from a cluster', function () {
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
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}")
        ->assertSuccessful()
        ->assertJsonPath('cluster.serversCount', 0)
        ->assertJsonPath('cluster.servers', []);

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('removes the bootstrap marker over ssh when deleting a bootstrapped v5 server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
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
        'status' => 'installed',
        'last_bootstrapped_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: "cleaned\n"),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}")
        ->assertSuccessful()
        ->assertJsonMissingPath('warning');

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'rm -f /etc/coolify/v5-node.json /etc/coolify/host-jwt');
    });

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('still deletes a bootstrapped v5 server when the ssh cleanup fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
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
        'status' => 'installed',
        'last_bootstrapped_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(errorOutput: "ssh failed\n", exitCode: 255),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}")
        ->assertSuccessful()
        ->assertJsonPath('warning', 'Could not clean up the server over SSH. Remove /etc/coolify/v5-node.json manually before re-adding this server.');

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeFalse();
});

it('rejects adding a v5 server beyond the cluster network pool capacity', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'namespaces' => ['default', 'preview'],
        'container_network_pool' => '10.211.0.0/24',
        'container_network_prefix' => 25,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-02',
            'host' => '203.0.113.11',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', "This cluster's network pools are full (1 server(s) max). Grow the pools or remove a server first.");

    expect($cluster->servers()->count())->toBe(1);
});

it('rejects duplicate v5 server node addresses within a team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'node_address' => '10.0.0.5',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->postJson("/v5/clusters/{$cluster->uuid}/servers", [
            'name' => 'prod-02',
            'host' => '203.0.113.11',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
            'node_address' => '10.0.0.5',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['node_address']);
});

it('blocks deleting a v5 server that still has applications', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
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
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now(),
    ]);
    V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-api',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->deleteJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Delete or move applications from this server before deleting it.');

    expect(V5Server::query()->whereKey($server->id)->exists())->toBeTrue();
});

it('updates editable v5 server builder details without changing networking', function () {
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
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '100%',
        'node_address' => '10.0.0.10',
        'wireguard_listen_port_override' => 51821,
        'wireguard_endpoint_override' => 'prod-01.example.com:51821',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => true,
            'builder_capacity' => 5,
            'builder_cpu_quota' => '350%',
            'host' => '198.51.100.99',
            'ssh_user' => 'admin',
            'ssh_port' => 2222,
            'node_address' => '10.0.0.99',
            'wireguard_endpoint_override' => 'changed.example.com:51821',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', true)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 5)
        ->assertJsonPath('cluster.servers.0.builderCpuQuota', '350%')
        ->assertJsonPath('cluster.servers.0.ingressEnabled', false)
        ->assertJsonPath('cluster.servers.0.host', '203.0.113.10')
        ->assertJsonMissingPath('cluster.servers.0.sshUser')
        ->assertJsonMissingPath('cluster.servers.0.sshPort')
        ->assertJsonPath('cluster.servers.0.nodeAddress', '10.0.0.10')
        ->assertJsonPath('cluster.servers.0.wireguardEndpointOverride', 'prod-01.example.com:51821');

    $server->refresh();

    expect($server->builder_enabled)->toBeTrue()
        ->and($server->builder_capacity)->toBe(5)
        ->and($server->builder_cpu_quota)->toBe('350%')
        ->and($server->capabilities)->toBe([])
        ->and($server->host)->toBe('203.0.113.10')
        ->and($server->ssh_user)->toBe('root')
        ->and($server->ssh_port)->toBe(22)
        ->and($server->node_address)->toBe('10.0.0.10')
        ->and($server->wireguard_endpoint_override)->toBe('prod-01.example.com:51821');
});

it('updates editable v5 server caddy ingress capability independently from builder', function () {
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
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => false,
            'builder_capacity' => 2,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy')
        ->assertJsonPath('cluster.servers.0.capabilities', ['ingress']);

    $server->refresh();

    expect($server->capabilities)->toBe(['ingress'])
        ->and($server->ingress_type)->toBe('caddy')
        ->and($server->builder_enabled)->toBeFalse()
        ->and($server->isIngress())->toBeTrue();
});

it('syncs caddy ingress routes through flux when enabling ingress on an installed server', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
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
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'nginx.example.com',
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'www.nginx.example.com',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->with(
            Mockery::type('string'),
            'caddy',
            Mockery::on(fn (string $caddyfile): bool => str_contains($caddyfile, 'import apps/*.caddy')),
            Mockery::on(fn (array $apps): bool => count($apps) === 1
                && str_contains($apps[0]['config'], 'http://nginx.example.com {')
                && str_contains($apps[0]['config'], 'http://www.nginx.example.com {')
                && str_contains($apps[0]['config'], 'reverse_proxy coolify-v5-nginx-test.default.coolify.internal:8080'))
        )
        ->andReturn('Caddy ingress applied.');
    expectCaddyIngressFirewallRule($fluxClient);
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.ingressEnabled', true)
        ->assertJsonPath('cluster.servers.0.ingressType', 'caddy');

    expect($server->refresh()->ingress_type)->toBe('caddy')
        ->and($server->ingress_status)->toBe('running');
});

it('returns flux error details when server ingress activation fails', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Project', 'production');
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
        'name' => 'edge-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-test',
        'status' => 'running',
        'mesh_namespace' => 'default',
        'ingress_enabled' => true,
        'internal_port' => 8080,
    ]);
    V5ApplicationDomain::query()->create([
        'application_id' => $application->id,
        'domain' => 'nginx.example.com',
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient
        ->shouldReceive('applyIngress')
        ->once()
        ->andThrow(new RuntimeException('validate Caddyfile: unrecognized directive'));
    app()->instance(FluxClient::class, $fluxClient);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => false,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
            'ingress_enabled' => true,
            'ingress_type' => 'caddy',
        ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Caddy rejected the generated ingress configuration. Check the domains and internal port, then try again.')
        ->assertJsonPath('detail', 'validate Caddyfile: unrecognized directive');

    expect($server->refresh()->capabilities)->toBe([])
        ->and($server->ingress_type)->toBeNull()
        ->and($server->ingress_status)->toBeNull();
});

it('keeps editable v5 server builder capacity when disabling builder', function () {
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
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 5,
        'builder_cpu_quota' => '350%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => false,
            'builder_capacity' => 5,
            'builder_cpu_quota' => '350%',
        ])
        ->assertSuccessful()
        ->assertJsonPath('cluster.servers.0.builderEnabled', false)
        ->assertJsonPath('cluster.servers.0.builderCapacity', 5);

    $server->refresh();

    expect($server->builder_enabled)->toBeFalse()
        ->and($server->builder_capacity)->toBe(5)
        ->and($server->builder_cpu_quota)->toBe('350%')
        ->and($server->capabilities)->toBe([]);
});

it('validates editable v5 server builder details', function () {
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
        'status' => 'installed',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => true,
            'builder_capacity' => 1001,
            'builder_cpu_quota' => str_repeat('a', 33),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity', 'builder_cpu_quota']);
});

it('requires positive editable v5 server builder capacity when builder is enabled', function () {
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
        'status' => 'installed',
        'capabilities' => [],
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'builder_cpu_quota' => '200%',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->patchJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}", [
            'builder_enabled' => true,
            'builder_capacity' => 0,
            'builder_cpu_quota' => '200%',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['builder_capacity']);
});
