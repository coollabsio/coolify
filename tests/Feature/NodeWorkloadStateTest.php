<?php

use App\Actions\Node\DetermineWorkloadState;
use App\Enums\NodeContainerManagementState;
use App\Enums\NodeWorkloadState;
use App\Models\Node;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'metadata' => ['container_inventory_observed_at' => now()->toIso8601String()],
    ]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $this->node->workloads()->attach($this->workload);
    $this->oldRevision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
    $this->currentRevision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
});

function managedWorkloadContainer(Node $node, NodeWorkload $workload, NodeWorkloadRevision $revision, string $state): NodeContainer
{
    return NodeContainer::factory()->create([
        'node_id' => $node->id,
        'node_workload_id' => $workload->id,
        'node_workload_revision_id' => $revision->id,
        'state' => $state,
        'management_state' => NodeContainerManagementState::MANAGED,
        'is_managed' => true,
    ]);
}

it('reports a current running revision as running', function () {
    managedWorkloadContainer($this->node, $this->workload, $this->currentRevision, 'running');

    expect(DetermineWorkloadState::run($this->node, $this->workload))->toBe(NodeWorkloadState::RUNNING);
});

it('reports a current non-running revision as stopped', function () {
    managedWorkloadContainer($this->node, $this->workload, $this->currentRevision, 'exited');

    expect(DetermineWorkloadState::run($this->node, $this->workload))->toBe(NodeWorkloadState::STOPPED);
});

it('reports an older observed revision as outdated', function () {
    managedWorkloadContainer($this->node, $this->workload, $this->oldRevision, 'running');

    expect(DetermineWorkloadState::run($this->node, $this->workload))->toBe(NodeWorkloadState::OUTDATED);
});

it('reports a workload without an observed managed container as missing', function () {
    expect(DetermineWorkloadState::run($this->node, $this->workload))->toBe(NodeWorkloadState::MISSING);
});

it('reports unknown before the first complete inventory snapshot', function () {
    $this->node->update(['metadata' => null]);

    expect(DetermineWorkloadState::run($this->node->fresh(), $this->workload))->toBe(NodeWorkloadState::UNKNOWN);
});

it('reports stale when the last complete inventory snapshot is old', function () {
    $this->node->update(['metadata' => ['container_inventory_observed_at' => now()->subMinutes(4)->toIso8601String()]]);

    expect(DetermineWorkloadState::run($this->node->fresh(), $this->workload))->toBe(NodeWorkloadState::STALE);
});
