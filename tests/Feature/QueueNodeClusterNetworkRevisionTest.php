<?php

use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Jobs\ReconcileNodeClusterNetworkJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->team = $this->owner->teams()->firstOrFail();
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'active', 'desired_revision' => 4]);
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->makeNode = function (bool $online) use ($key): Node {
        $node = Node::factory()->create([
            'team_id' => $this->team->id,
            'private_key_id' => $key->id,
            'node_cluster_id' => $this->cluster->id,
            'is_usable' => true,
        ]);
        if ($online) {
            Cache::put($node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);
        }

        return $node;
    };
});

it('bumps the desired revision once and queues one reconciliation for the reachable Nodes', function () {
    $first = ($this->makeNode)(true);
    ($this->makeNode)(false);
    $third = ($this->makeNode)(true);

    expect(QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner))->toBeTrue();

    expect($this->cluster->refresh()->desired_revision)->toBe(5);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 1);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn (ReconcileNodeClusterNetworkJob $job) => $job->clusterId === $this->cluster->id
        && $job->userId === $this->owner->id
        && $job->nodeIds === [$first->id, $third->id]
        && $job->onlyDueNodes === false);
});

it('runs as a team admin when the requesting user cannot manage the cluster', function () {
    ($this->makeNode)(true);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    QueueNodeClusterNetworkRevision::run($this->cluster, $member);

    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn (ReconcileNodeClusterNetworkJob $job) => $job->userId === $this->owner->id);
});

it('runs as a team admin when no user requested the change', function () {
    ($this->makeNode)(true);

    QueueNodeClusterNetworkRevision::run($this->cluster);

    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, fn (ReconcileNodeClusterNetworkJob $job) => $job->userId === $this->owner->id);
});

it('only bumps the revision while no Node can receive network commands', function () {
    ($this->makeNode)(false);

    expect(QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner))->toBeFalse();

    expect($this->cluster->refresh()->desired_revision)->toBe(5);
    Queue::assertNothingPushed();
});

it('only bumps the revision of a cluster whose network was never activated', function () {
    $this->cluster->update(['network_status' => 'pending', 'last_reconciled_at' => null]);
    ($this->makeNode)(true);

    expect(QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner))->toBeFalse();

    expect($this->cluster->refresh()->desired_revision)->toBe(5);
    Queue::assertNothingPushed();
});

it('retries a failed network automatically', function () {
    $this->cluster->update(['network_status' => 'error', 'last_reconciled_at' => null]);
    ($this->makeNode)(true);

    expect(QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner))->toBeTrue();

    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 1);
});

it('bumps the revision on every call', function () {
    ($this->makeNode)(true);

    QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner);
    QueueNodeClusterNetworkRevision::run($this->cluster, $this->owner);

    expect($this->cluster->refresh()->desired_revision)->toBe(6);
    Queue::assertPushed(ReconcileNodeClusterNetworkJob::class, 2);
});
