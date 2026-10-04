<?php

use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeFirewallRule;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    InstanceSettings::forceCreate(['id' => 0]);
    $this->seed([UserSeeder::class, TeamSeeder::class]);
    Queue::fake();
    $key = PrivateKey::factory()->create(['team_id' => 0]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => 0, 'name' => 'Development QEMU mesh', 'network_status' => 'active', 'desired_revision' => 4]);
    $this->nodes = collect(['a' => '10.240.0.2', 'b' => '10.240.0.3'])->map(fn (string $wireguardIp, string $letter) => Node::factory()->create([
        'team_id' => 0,
        'uuid' => "development-qemu-node-worker-{$letter}",
        'private_key_id' => $key->id,
        'node_cluster_id' => $this->cluster->id,
        'wireguard_ip' => $wireguardIp,
        'network_applied_revision' => 4,
        'corrosion_status' => 'converged',
    ]));
    $this->workload = NodeWorkload::factory()->create(['team_id' => 0]);
    $this->nodes['a']->workloads()->attach($this->workload, ['container_ip' => '100.64.0.2']);
});

function nodeEndToEnd(array $arguments): array
{
    $exitCode = Artisan::call('dev:node-e2e', $arguments);

    return [$exitCode, json_decode(Artisan::output(), true)];
}

it('only runs in development mode', function () {
    config(['app.env' => 'production']);

    expect(Artisan::call('dev:node-e2e', ['action' => 'status']))->toBe(Command::FAILURE);
});

it('rejects unknown actions', function () {
    expect(Artisan::call('dev:node-e2e', ['action' => 'reboot']))->toBe(Command::INVALID);
});

it('prints the cluster, Node, workload, and trust state as JSON', function () {
    [$exitCode, $status] = nodeEndToEnd(['action' => 'status']);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($status['cluster']['network_status'])->toBe('active')
        ->and($status['cluster']['desired_revision'])->toBe(4)
        ->and($status['nodes'])->toHaveKeys(['a', 'b'])
        ->and($status['nodes']['a']['network_state'])->toBe('converged')
        ->and($status['nodes']['b']['connected'])->toBeFalse()
        ->and($status['workload']['uuid'])->toBe($this->workload->uuid)
        ->and($status['workload']['container_name'])->toBe('coolify-'.$this->workload->uuid.'-main')
        ->and($status['workload']['container_ips'])->toBe(['100.64.0.2'])
        ->and($status['workload']['active_operations'])->toBe([])
        ->and($status['trust']['rotation'])->toBeNull();
});

it('adds and removes the end-to-end firewall rule with a new revision and a queued reconciliation', function () {
    [$exitCode, $added] = nodeEndToEnd(['action' => 'firewall-add', '--port' => 65000]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($added['desired_revision'])->toBe(5)
        ->and(NodeFirewallRule::query()->where('source_node_id', $this->nodes['a']->id)->where('port', 65000)->exists())->toBeTrue()
        ->and($this->cluster->refresh()->network_status)->toBe('reconciling');
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn (ReconcileNodeClusterNetworkJob $job): bool => $job->nodeIds === null);

    [, $removed] = nodeEndToEnd(['action' => 'firewall-remove', '--port' => 65000]);

    expect($removed)->toBe(['removed' => 1, 'desired_revision' => 6])
        ->and(NodeFirewallRule::query()->exists())->toBeFalse();
});

it('reports a failed action as JSON with a failure exit code', function () {
    [$exitCode, $result] = nodeEndToEnd(['action' => 'operation', 'argument' => 'missing']);

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($result)->toHaveKey('error');
});
