<?php

use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use Database\Seeders\V5DevLimaSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('syncs dev Lima VMs into v5 clusters and servers', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Dev Lima Key');

    $exitCode = Artisan::call('v5:sync-dev-lima-servers', [
        '--team-id' => $team->id,
        '--user-id' => $user->id,
        '--cluster' => 'Development-Lima',
        '--server' => [
            'coold-dev|host.docker.internal|developer|61332|100.64.0.1',
            'coold-dev-2|host.docker.internal|developer|61379|100.64.0.2',
        ],
    ]);

    expect($exitCode)->toBe(0)
        ->and(Cluster::query()->where('name', 'Development-Lima')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'host.docker.internal')->where('node_address', '100.64.0.1')->where('wireguard_management_ip', '100.64.0.1')->where('ssh_port', 61332)->where('private_key_id', $privateKey->id)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'host.docker.internal')->where('node_address', '100.64.0.2')->where('wireguard_management_ip', '100.64.0.2')->where('ssh_port', 61379)->where('private_key_id', $privateKey->id)->exists())->toBeTrue();
});

it('updates legacy dev Lima hostnames to Docker reachable SSH endpoints', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Dev Lima Key');
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
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $exitCode = Artisan::call('v5:sync-dev-lima-servers', [
        '--team-id' => $team->id,
        '--user-id' => $user->id,
        '--cluster' => 'Development-Lima',
        '--server' => [
            'coold-dev|host.docker.internal|developer|61332',
        ],
    ]);

    expect($exitCode)->toBe(0)
        ->and(V5Server::query()->where('name', 'coold-dev')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'host.docker.internal')->where('ssh_port', 61332)->exists())->toBeTrue()
        ->and(V5Server::query()->where('host', 'lima-coold-dev')->exists())->toBeFalse();
});

it('seeds dev Lima VMs into v5 clusters and servers idempotently', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    createV5PrivateKey($team, 'Dev Lima Key');

    (new V5DevLimaSeeder)->run();
    (new V5DevLimaSeeder)->run();

    $cluster = Cluster::query()->where('name', 'Development-Lima')->sole();

    expect($cluster->team_id)->toBe($team->id)
        ->and($cluster->created_by_user_id)->toBe($user->id)
        ->and($cluster->description)->toBe('Local Lima development cluster managed by scripts/dev.sh.')
        ->and(V5Server::query()->count())->toBe(2)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'coold-dev.local')->where('ssh_user', 'coolify')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'coold-dev-2.local')->where('ssh_user', 'coolify')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev')->where('node_address', '100.64.0.1')->where('wireguard_management_ip', '100.64.0.1')->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('node_address', '100.64.0.2')->where('wireguard_management_ip', '100.64.0.2')->exists())->toBeTrue()
        ->and(V5Server::query()->where('status', 'installed')->count())->toBe(2)
        ->and(V5Server::query()->where('builder_enabled', false)->where('builder_capacity', 0)->count())->toBe(2)
        ->and(V5Server::query()->where('cluster_id', $cluster->id)->count())->toBe(2);
});

it('seeds dev Lima VMs by updating existing named servers', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    createV5PrivateKey($team, 'Dev Lima Key');
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
        'name' => 'coold-dev',
        'host' => 'old-host.local',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'builder_enabled' => false,
        'builder_capacity' => 0,
        'last_bootstrapped_at' => now()->subDay(),
    ]);

    (new V5DevLimaSeeder)->run();

    expect(V5Server::query()->where('name', 'coold-dev')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'coold-dev.local')->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->count())->toBe(2);
});

it('configures v5 dev lima host resolver for coolify internal dns', function () {
    $script = file_get_contents(base_path('scripts/coold-vm.sh'));

    expect($script)
        ->toContain('configure_system_resolved')
        ->toContain('ensure_mesh_dns_anchor')
        ->toContain('coolify-v5-mesh-dns-anchor')
        ->toContain('resolvectl dns podman1 "$CONTAINER_GATEWAY"')
        ->toContain("resolvectl domain podman1 '~coolify.internal'")
        ->toContain('resolvectl default-route podman1 false');
});
