<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateNodeCluster;
use App\Livewire\NodeCluster\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $team = $this->user->teams()->firstOrFail();
    session(['currentTeam' => $team]);
    $this->cluster = CreateNodeCluster::run($team, $this->user, 'Pressure mesh');
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => [
            'cpu_usage_percent' => 50,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 500,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 500,
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    AssignNodeToCluster::run($this->cluster, $this->node);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $this->node->workloads()->attach($this->workload);
    $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
});

it('allows deployment when the current Node snapshot is below the cluster limits', function () {
    $deployment = CreateDeploymentOperation::run($this->node, $this->revision, $this->user);

    expect($deployment['created'])->toBeTrue()
        ->and(NodeOperation::query()->count())->toBe(1);
});

it('rejects a reservation that does not fit below the configured cluster headroom', function () {
    Cache::put($this->node->cacheKey(), ['capabilities' => ['workload.deploy.v1', 'workload.resources.v1']]);
    $this->node->update(['metadata' => [...$this->node->metadata, 'cpus' => 2]]);
    $this->revision->update(['configuration' => [
        'resources' => ['cpu_reservation' => 1.9, 'memory_reservation_bytes' => 950],
    ]]);

    expect(fn () => CreateDeploymentOperation::run($this->node->fresh(), $this->revision->fresh(), $this->user))
        ->toThrow(DomainException::class, 'reservation');
});

it('requires the resource capability only when a revision has resource settings', function () {
    Cache::put($this->node->cacheKey(), ['capabilities' => ['workload.deploy.v1']]);
    $this->revision->update(['configuration' => [
        'resources' => ['memory_limit_bytes' => 536_870_912],
    ]]);

    expect(fn () => CreateDeploymentOperation::run($this->node, $this->revision->fresh(), $this->user))
        ->toThrow(RuntimeException::class, 'Upgrade Sentinel')
        ->and(NodeOperation::query()->count())->toBe(0);
});

it('rejects deployments before state changes when a configurable pressure limit is reached', function (string $metric, array $metadata, string $message) {
    $desiredState = $this->workload->desired_state;
    $this->cluster->update([$metric => 80]);
    $this->node->update(['metadata' => [...$this->node->metadata, ...$metadata]]);

    expect(fn () => CreateDeploymentOperation::run($this->node->fresh(), $this->revision, $this->user))
        ->toThrow(DomainException::class, $message)
        ->and(NodeOperation::query()->count())->toBe(0)
        ->and($this->workload->fresh()->desired_state)->toBe($desiredState);
})->with([
    'CPU' => ['cpu_pressure_threshold', ['cpu_usage_percent' => 80], 'CPU pressure'],
    'memory' => ['memory_pressure_threshold', ['memory_used_bytes' => 800], 'memory pressure'],
    'disk' => ['disk_pressure_threshold', ['disk_available_bytes' => 200], 'disk pressure'],
]);

it('rejects offline, unusable, stale, and incomplete Nodes', function (array $nodeChanges, array $metadata, string $message) {
    $this->node->update([
        ...$nodeChanges,
        'metadata' => [...$this->node->metadata, ...$metadata],
    ]);

    expect(fn () => CreateDeploymentOperation::run($this->node->fresh(), $this->revision, $this->user))
        ->toThrow(DomainException::class, $message);
})->with([
    'offline' => [['is_reachable' => false], [], 'not reachable'],
    'unusable' => [['is_usable' => false], [], 'not usable'],
    'stale' => [[], ['collected_at' => now()->subMinutes(6)->toIso8601String()], 'older than 5 minutes'],
    'incomplete' => [[], ['memory_used_bytes' => null], 'resource data is incomplete'],
]);

it('lets cluster administrators customize deployment pressure limits', function () {
    Livewire::test(Show::class, ['cluster_uuid' => $this->cluster->uuid])
        ->assertSee('Deployment pressure')
        ->set('cpuPressureThreshold', 92)
        ->set('memoryPressureThreshold', 88)
        ->set('diskPressureThreshold', 85)
        ->set('resourceStaleAfterMinutes', 10)
        ->call('savePressurePolicy')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->cluster->fresh())->toMatchArray([
        'cpu_pressure_threshold' => 92,
        'memory_pressure_threshold' => 88,
        'disk_pressure_threshold' => 85,
        'resource_stale_after_minutes' => 10,
    ]);
});

it('validates pressure policy limits', function () {
    Livewire::test(Show::class, ['cluster_uuid' => $this->cluster->uuid])
        ->set('cpuPressureThreshold', 0)
        ->set('memoryPressureThreshold', 101)
        ->set('resourceStaleAfterMinutes', 61)
        ->call('savePressurePolicy')
        ->assertHasErrors(['cpuPressureThreshold', 'memoryPressureThreshold', 'resourceStaleAfterMinutes']);
});

it('forbids members from changing the cluster pressure policy', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->cluster->team_id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->cluster->team]);

    Livewire::test(Show::class, ['cluster_uuid' => $this->cluster->uuid])
        ->call('savePressurePolicy')
        ->assertForbidden();
});
