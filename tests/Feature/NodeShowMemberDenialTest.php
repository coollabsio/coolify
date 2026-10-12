<?php

use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\RepairFluxTrust;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\FetchFluxNodeInformation;
use App\Actions\Sentinel\PingFluxConnection;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    Process::fake();
    Http::fake();
    Queue::fake();

    $owner = User::factory()->create();
    $this->team = $owner->teams()->firstOrFail();
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'active']);
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $makeNode = fn (): Node => Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $cluster->id,
        'private_key_id' => $key->id,
        'is_usable' => true,
    ]);
    $this->node = $makeNode();
    $this->target = $makeNode();
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id, 'internal_dns_name' => 'web']);
    $this->workload->nodes()->attach($this->node);
    $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);

    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
});

it('denies members every Sentinel and workload action without side effects', function (string $method, Closure $arguments) {
    foreach ([ValidateNode::class, FetchFluxNodeInformation::class, RepairFluxTrust::class, PingFluxConnection::class,
        PrepareNodeWorkloadRevision::class, CreateDeploymentOperation::class, CreateMoveOperation::class] as $action) {
        $action::shouldRun()->never();
    }
    $nodeBefore = $this->node->fresh()->getAttributes();
    $revisionCount = NodeWorkloadRevision::query()->count();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->set('moveTargets', [$this->workload->uuid => $this->target->uuid])
        ->call($method, ...$arguments->call($this))
        ->assertDispatched('error', 'This action is unauthorized.')
        ->assertNotDispatched('success');

    Process::assertNothingRan();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(NodeOperation::query()->count())->toBe(0)
        ->and(NodeWorkloadRevision::query()->count())->toBe($revisionCount)
        ->and($this->node->fresh()->getAttributes())->toBe($nodeBefore)
        ->and($this->workload->fresh()->nodes()->pluck('nodes.id')->all())->toBe([$this->node->id]);
})->with([
    'restartSentinel' => ['restartSentinel', fn () => []],
    'validateNode' => ['validateNode', fn () => []],
    'refreshInformation' => ['refreshInformation', fn () => []],
    'repairFluxTrust' => ['repairFluxTrust', fn () => []],
    'testFluxConnection' => ['testFluxConnection', fn () => []],
    'deployRevision' => ['deployRevision', fn () => [$this->revision->uuid]],
    'moveWorkload' => ['moveWorkload', fn () => [$this->workload->uuid]],
]);
