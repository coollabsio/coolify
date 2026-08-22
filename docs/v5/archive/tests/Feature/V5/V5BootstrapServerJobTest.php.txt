<?php

use App\Events\V5ClusterUpdated;
use App\Jobs\V5BootstrapServerJob;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\AgentTokenIssuer;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('broadcasts v5 cluster updates when bootstrap state changes', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

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

    Queue::fake();
    Event::fake([V5ClusterUpdated::class]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted();

    Event::assertDispatched(V5ClusterUpdated::class, fn ($event): bool => $event->clusterId === $cluster->id
        && $event->teamId === $team->id);

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    expect(Event::dispatched(V5ClusterUpdated::class)->count())->toBeGreaterThanOrEqual(2);
});

it('writes quiet ssh options for v5 bootstrap ssh config', function () {
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
    ]);
    $server->load('privateKey');

    $tempDirectory = storage_path('framework/testing/v5_bootstrap_'.str()->random(8));
    mkdir($tempDirectory, 0700, true);

    try {
        $job = new V5BootstrapServerJob($cluster->id, $server->id);
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('writeBootstrapSshConfig');
        $method->setAccessible(true);

        $sshConfigLocation = $method->invoke($job, collect([$server]), $tempDirectory);
        $config = file_get_contents($sshConfigLocation);

        expect($config)->toContain('  LogLevel ERROR')
            ->and($config)->toContain('  UserKnownHostsFile /dev/null')
            ->and($config)->toContain('  StrictHostKeyChecking no');
    } finally {
        collect(scandir($tempDirectory) ?: [])
            ->reject(fn (string $file) => in_array($file, ['.', '..'], true))
            ->each(fn (string $file) => @unlink($tempDirectory.'/'.$file));
        @rmdir($tempDirectory);
    }
});

it('bootstraps a single v5 server with the Coolify CLI', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');
    fakeAgentTokenIssuer();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'namespaces' => ['default', 'preview'],
        'container_network_pool' => '10.211.0.0/16',
        'container_network_prefix' => 25,
        'wireguard_management_pool' => '100.65.0.0/16',
        'wireguard_interface' => 'wg-prod',
        'wireguard_listen_port' => 51830,
        'coold_version' => 'v0.2.0',
        'corrosion_version' => 'v1.1.0',
        'corrosion_gossip_port' => 8788,
        'corrosion_api_port' => 8081,
        'builder_enabled' => true,
        'builder_capacity' => 4,
        'builder_cpu_quota' => '400%',
        'builder_memory_max' => '4G',
        'builder_timeout_secs' => 2400,
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 2222,
        'status' => 'added',
        'capabilities' => ['builder'],
        'builder_enabled' => true,
        'builder_capacity' => 4,
        'wireguard_listen_port_override' => 51831,
        'wireguard_endpoint_override' => 'prod-01.example.com:51831',
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('message', 'Bootstrap queued.')
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.lastBootstrapAction', 'bootstrap')
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'queued')
        ->assertJsonPath('cluster.servers.0.lastBootstrapOutput', 'Queued Coolify bootstrap for prod-01.');

    Queue::assertPushed(V5BootstrapServerJob::class);

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    Process::assertRan(function ($process): bool {
        $command = $process->command;
        $node = cliFlagValue($command, '--nodes');

        return $command[0] === '/tmp/coolify'
            && in_array('init', $command, true)
            && in_array('bootstrap', $command, true)
            && cliFlagValue($command, '--format') === 'json'
            && in_array('--ssh-config', $command, true)
            && cliFlagValue($command, '--ssh-user') === 'root'
            && is_string($node)
            && str_starts_with($node, 'v5-server-')
            && cliFlagValue($command, '--namespaces') === 'default,preview'
            && cliFlagValue($command, '--container-pool') === '10.211.0.0/16'
            && cliFlagValue($command, '--wg-mgmt-pool') === '100.65.0.0/16'
            && cliFlagValue($command, '--wg-interface') === 'wg-prod'
            && cliFlagValue($command, '--coold-version') === 'v0.2.0'
            && cliFlagValue($command, '--corrosion-version') === 'v1.1.0'
            && cliFlagValue($command, '--wg-listen-port-overrides') === $node.'=51831'
            && cliFlagValue($command, '--wg-endpoint-overrides') === $node.'=prod-01.example.com:51831'
            && ! in_array('--enable-builder', $command, true)
            && in_array('--yes', $command, true)
            && ! in_array('--new-nodes', $command, true);
    });
    Process::assertRan(fn ($process): bool => in_array('bootstrap', $process->command, true) && $process->timeout === 7200);

    $cluster->refresh();

    expect($server->status)->toBe('installed')
        ->and($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->last_bootstrap_output)->toContain('Bootstrap completed')
        ->and($server->last_bootstrapped_at)->not->toBeNull();
});

it('does not run a macOS development Coolify CLI binary from Docker during v5 server bootstrap', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/usr/local/bin/coolify');

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

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted();

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertRan(fn ($process): bool => $process->command[0] === '/usr/local/bin/coolify');
});

it('keeps a v5 server added when Coolify CLI bootstrap fails', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

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

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('cluster.servers.0.status', 'added')
        ->assertJsonPath('cluster.servers.0.lastBootstrappedAt', null)
        ->assertJsonPath('cluster.servers.0.lastBootstrapStatus', 'queued');

    Process::fake([
        '*' => Process::result(errorOutput: "bootstrap failed\n", exitCode: 1),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toBe('bootstrap failed')
        ->and($server->last_bootstrapped_at)->toBeNull();
});

it('persists v5 bootstrap mesh assignments and enrolls coold into flux', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'namespaces' => ['default', 'preview'],
        'wireguard_management_pool' => '100.65.0.0/16',
        'container_network_pool' => '10.211.0.0/16',
        'container_network_prefix' => 25,
        'coold_version' => 'v0.9.9',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'ubuntu',
        'ssh_port' => 2222,
        'status' => 'added',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    $issuer = Mockery::mock(AgentTokenIssuer::class);
    $issuer
        ->shouldReceive('issueForServer')
        ->once()
        ->with(Mockery::on(fn (V5Server $server): bool => $server->wireguard_management_ip === '100.65.0.1'))
        ->andReturn('signed-host-jwt');
    app()->instance(AgentTokenIssuer::class, $issuer);

    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: ''))
            ->push(Process::result(output: json_encode([
                'results' => [],
                'verified' => [[
                    'host' => "v5-server-{$server->uuid}",
                    'wireguard_ip' => '100.65.0.1',
                    'peer_count' => 0,
                    'status' => 'ok',
                ]],
            ], JSON_THROW_ON_ERROR)."\n"))
            ->push(Process::result(output: "public-key\n"))
            ->push(Process::result(output: "default=10.211.0.0/25\npreview=10.211.0.128/25\n"))
            ->push(Process::result(output: "marker written\n"))
            ->push(Process::result(output: "coold restarted\n")),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->wireguard_management_ip)->toBe('100.65.0.1')
        ->and($server->wireguard_public_key)->toBe('public-key')
        ->and($server->container_subnets)->toBe([
            'default' => '10.211.0.0/25',
            'preview' => '10.211.0.128/25',
        ])
        ->and($server->node_address)->toBe('100.65.0.1')
        ->and($server->status)->toBe('installed')
        ->and($server->coold_version)->toBe('v0.9.9')
        ->and($server->last_bootstrapped_at)->not->toBeNull();

    Process::assertRan(function ($process) use ($server): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'COOLIFY_COOLD_FLUX_URL=http://coolify.example.com:6443')
            && str_contains($command, 'COOLIFY_COOLD_HOST_ID='.$server->uuid)
            && str_contains($command, 'COOLIFY_COOLD_HOST_JWT_PATH=/etc/coolify/host-jwt')
            && str_contains($command, 'signed-host-jwt')
            && str_contains($command, 'chmod 600 /etc/coolify/host-jwt')
            && str_contains($command, 'systemctl restart coold.service');
    });

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'wg show')
            && str_contains($command, "if [ \"$(id -u)\" != \"0\" ]; then SUDO='sudo'; fi");
    });

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'podman network inspect "coolify-${ns}-mesh"')
            && str_contains($command, "if [ \"$(id -u)\" != \"0\" ]; then SUDO='sudo'; fi");
    });
});

it('waits for the bootstrapped host to connect to flux before marking the server installed', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');
    Config::set('flux.bootstrap_host_connection_timeout_seconds', 1);

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'wireguard_management_pool' => '100.65.0.0/16',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'ubuntu',
        'ssh_port' => 2222,
        'status' => 'added',
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    fakeAgentTokenIssuer();
    $this->mock(FluxClient::class, function ($mock) use ($server): void {
        $mock->shouldReceive('cooldLogs')
            ->once()
            ->with($server->uuid, 1)
            ->andReturn('coold connected');
    });

    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: ''))
            ->push(Process::result(output: json_encode([
                'verified' => [[
                    'host' => "v5-server-{$server->uuid}",
                    'wireguard_ip' => '100.65.0.1',
                ]],
            ], JSON_THROW_ON_ERROR)."\n"))
            ->push(Process::result(output: "public-key\n"))
            ->push(Process::result(output: "default=10.211.0.0/25\n"))
            ->push(Process::result(output: "marker written\n"))
            ->push(Process::result(output: "coold restarted\n")),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->status)->toBe('installed')
        ->and($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->wireguard_management_ip)->toBe('100.65.0.1');
});

it('reads the WireGuard management IP from the host when bootstrap output does not include it', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'wireguard_interface' => 'wg-prod',
    ]);
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'ubuntu',
        'ssh_port' => 2222,
        'status' => 'added',
        'node_address' => '203.0.113.10',
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    $issuer = Mockery::mock(AgentTokenIssuer::class);
    $issuer
        ->shouldReceive('issueForServer')
        ->once()
        ->with(Mockery::on(fn (V5Server $server): bool => $server->wireguard_management_ip === '100.65.0.1'))
        ->andReturn('signed-host-jwt');
    app()->instance(AgentTokenIssuer::class, $issuer);

    Process::fake([
        '*' => Process::sequence()
            ->push(Process::result(output: ''))
            ->push(Process::result(output: json_encode([
                'results' => [],
                'verified' => [],
            ], JSON_THROW_ON_ERROR)."\n"))
            ->push(Process::result(output: "100.65.0.1\n"))
            ->push(Process::result(output: "public-key\n"))
            ->push(Process::result(output: "default=10.211.0.0/25\n"))
            ->push(Process::result(output: "marker written\n"))
            ->push(Process::result(output: "coold restarted\n")),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->wireguard_management_ip)->toBe('100.65.0.1')
        ->and($server->node_address)->toBe('100.65.0.1')
        ->and($server->status)->toBe('installed');

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'ip -4 -o addr show dev')
            && str_contains($command, 'wg-prod');
    });
});

it('fails the bootstrap when the flux url is not configured', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', '');

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
        'status' => 'added',
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toContain('COOLIFY_COOLD_FLUX_URL')
        ->and($server->last_bootstrapped_at)->toBeNull();
});

it('writes the bootstrap marker before flux enrollment so a failed enrollment can resume', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');
    fakeAgentTokenIssuer();

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
        'status' => 'added',
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    Process::fake([
        '*systemctl restart coold*' => Process::result(errorOutput: "enrollment boom\n", exitCode: 1),
        '*' => Process::result(output: "Bootstrap completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toContain('enrollment boom')
        ->and($server->last_bootstrap_output)->toContain('retrying this bootstrap is safe')
        ->and($server->last_bootstrapped_at)->toBeNull();

    Process::assertRan(function ($process): bool {
        $command = implode(' ', array_map(fn ($part) => is_string($part) ? $part : json_encode($part), $process->command));

        return str_contains($command, 'v5-node.json') && str_contains($command, 'base64 -d');
    });
});

it('exits the bootstrap job when its claim was superseded', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

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
        'status' => 'added',
        'last_bootstrap_status' => 'failed',
        'last_bootstrap_ran_at' => now(),
    ]);

    Process::fake();

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertNothingRan();
    expect($server->refresh()->last_bootstrap_status)->toBe('failed');
});

it('recovers a stale queued v5 bootstrap claim', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Production SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
    ]);
    $staleServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'added',
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now()->subMinutes(20),
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
    ]);

    Queue::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$server->uuid}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('message', 'Bootstrap queued.');

    Queue::assertPushed(V5BootstrapServerJob::class);

    expect($staleServer->refresh()->last_bootstrap_status)->toBe('failed')
        ->and($staleServer->last_bootstrap_output)->toContain('timed out or its worker died')
        ->and($server->refresh()->last_bootstrap_status)->toBe('queued');
});

it('extends a v5 cluster when bootstrapping a new server', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');
    fakeAgentTokenIssuer();

    [$user, $team] = createV5UserWithTeam();
    $oldPrivateKey = createV5PrivateKey($team, 'Old SSH Key');
    $newPrivateKey = createV5PrivateKey($team, 'New SSH Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Production Mesh',
        'description' => null,
        'coold_version' => 'v0.3.0',
        'corrosion_version' => 'v1.2.0',
    ]);
    $oldServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $oldPrivateKey->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'ubuntu',
        'ssh_port' => 2222,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now()->subDay(),
    ]);
    $newServer = V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $newPrivateKey->id,
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
        ->postJson("/v5/clusters/{$cluster->uuid}/servers/{$newServer->uuid}/bootstrap")
        ->assertAccepted()
        ->assertJsonPath('cluster.servers.1.lastBootstrapAction', 'extend')
        ->assertJsonPath('cluster.servers.1.lastBootstrapStatus', 'queued');

    Process::fake([
        '*' => Process::result(output: "Extend completed\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $newServer->id))->handle();

    Process::assertRan(function ($process) use ($oldServer, $newServer): bool {
        $command = $process->command;
        $oldNode = "v5-server-{$oldServer->uuid}";
        $newNode = "v5-server-{$newServer->uuid}";

        return in_array('extend', $command, true)
            && in_array("{$oldNode},{$newNode}", $command, true)
            && in_array('--new-nodes', $command, true)
            && in_array($newNode, $command, true)
            && cliFlagValue($command, '--ssh-user') === 'root'
            && in_array('--ssh-config', $command, true)
            && cliFlagValue($command, '--coold-version') === 'v0.3.0'
            && cliFlagValue($command, '--corrosion-version') === 'v1.2.0';
    });

    $oldServer->refresh();
    $newServer->refresh();

    expect($oldServer->status)->toBe('installed')
        ->and($newServer->status)->toBe('installed')
        ->and($newServer->last_bootstrap_status)->toBe('succeeded')
        ->and($newServer->last_bootstrap_output)->toContain('Extend completed')
        ->and($newServer->last_bootstrapped_at)->not->toBeNull();
});

it('adopts a re-added v5 server that is already bootstrapped for the same cluster', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');
    Config::set('coold.flux_url', 'http://coolify.example.com:6443');
    fakeAgentTokenIssuer();

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
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'cluster_id' => $cluster->id,
            'cluster_uuid' => $cluster->uuid,
            'server_uuid' => 'server-previous-uuid',
            'wireguard_management_ip' => '100.64.0.10',
            'wireguard_public_key' => 'public-key',
            'container_subnets' => ['default' => '10.210.0.0/24'],
        ], JSON_THROW_ON_ERROR)."\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === '/tmp/coolify');

    $server->refresh();

    expect($server->status)->toBe('installed')
        ->and($server->uuid)->toBe('server-previous-uuid')
        ->and($server->wireguard_management_ip)->toBe('100.64.0.10')
        ->and($server->wireguard_public_key)->toBe('public-key')
        ->and($server->container_subnets)->toBe(['default' => '10.210.0.0/24'])
        ->and($server->last_bootstrap_status)->toBe('succeeded')
        ->and($server->last_bootstrap_output)->toBe('Adopted existing Coolify bootstrap state for this cluster.')
        ->and($server->last_bootstrapped_at)->not->toBeNull();
});

it('blocks bootstrapping a v5 server that belongs to another cluster', function () {
    createSharedUserAndTeamTables();
    Config::set('coold.coolify_cli_bin', '/tmp/coolify');

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
        'last_bootstrap_status' => 'queued',
        'last_bootstrap_ran_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'cluster_id' => $cluster->id + 100,
            'server_uuid' => 'server-other-uuid',
        ], JSON_THROW_ON_ERROR)."\n"),
    ]);

    Event::fake([V5ClusterUpdated::class]);

    (new V5BootstrapServerJob($cluster->id, $server->id))->handle();

    Process::assertNotRan(fn ($process): bool => $process->command[0] === '/tmp/coolify');

    $server->refresh();

    expect($server->status)->toBe('added')
        ->and($server->last_bootstrap_status)->toBe('failed')
        ->and($server->last_bootstrap_output)->toContain('already bootstrapped for another cluster')
        ->and($server->last_bootstrapped_at)->toBeNull();
});
