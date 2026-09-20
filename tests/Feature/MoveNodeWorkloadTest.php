<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\CreateNodeCluster;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\MoveNodeWorkloadJob;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('publishes a ready target before it removes and withdraws the source workload', function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $cluster = CreateNodeCluster::run($team, $user, 'Move mesh');
    $source = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $target = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => [
            'cpu_usage_percent' => 10,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 100,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 900,
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    AssignNodeToCluster::run($cluster, $source);
    AssignNodeToCluster::run($cluster, $target);
    $cluster->update(['network_status' => 'active']);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'Move App']);
    $source->workloads()->attach($workload);
    $revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $workload->id,
        'configuration' => ['ports' => [[
            'host_ip' => $source->wireguard_ip,
            'host_port' => 18080,
            'container_port' => 80,
            'protocol' => 'tcp',
        ]], 'restart_policy' => 'unless-stopped'],
    ]);
    $events = [];

    Http::fake(function (Request $request) use ($target, $workload, $revision, &$events) {
        if (str_ends_with($request->url(), '/v1/commands/workload.deploy')) {
            $events[] = 'deploy-target';
            expect($request['ports'][0]['host_ip'])->toBe($target->wireguard_ip);

            return Http::response([
                'command_id' => $request['command_id'],
                'observed_at_unix_ms' => 1_700_000_000_000,
                'runtime_id' => 'target-runtime',
                'name' => 'coolify-'.$workload->uuid.'-main',
                'image' => $revision->image,
            ]);
        }
        if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
            $events[] = 'remove-source';

            return Http::response([
                'command_id' => $request['command_id'],
                'observed_at_unix_ms' => 1_700_000_002_000,
                'name' => 'coolify-'.$workload->uuid.'-main',
                'action' => 'remove',
            ]);
        }
        if (str_ends_with($request->url(), '/v1/commands/container.list')) {
            return Http::response([
                'command_id' => 'inventory-'.$request['server_id'],
                'observed_at_unix_ms' => 1_700_000_001_000,
                'containers' => $request['server_id'] === $target->uuid ? [[
                    'runtime_id' => 'target-runtime',
                    'name' => 'coolify-'.$workload->uuid.'-main',
                    'image' => $revision->image,
                    'state' => 'running',
                    'health_status' => 'healthy',
                    'restart_count' => 0,
                    'ports' => [],
                    'labels' => [
                        'coolify.managed' => 'true',
                        'coolify.instance' => 'instance-test',
                        'coolify.workload' => $workload->uuid,
                        'coolify.revision' => $revision->uuid,
                        'coolify.component' => 'main',
                    ],
                ]] : [],
            ]);
        }

        $workloadEndpoints = collect($request['endpoints'])->where('namespace', 'default');
        $events[] = $request['server_id'] === $target->uuid && $workloadEndpoints->isNotEmpty()
            ? 'publish-target'
            : 'withdraw-source';

        return Http::response([
            'command_id' => $request['command_id'],
            'observed_at_unix_ms' => 1_700_000_001_100,
            'owner_node_ip' => $request['owner_node_ip'],
            'endpoint_count' => count($request['endpoints']),
        ]);
    });

    $operation = CreateMoveOperation::run($source, $target, $revision, $user);
    (new MoveNodeWorkloadJob($operation->id))->handle();

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($source->workloads()->whereKey($workload->id)->exists())->toBeFalse()
        ->and($target->workloads()->whereKey($workload->id)->exists())->toBeTrue()
        ->and($workload->refresh()->desired_state)->toBe(NodeWorkloadDesiredState::RUNNING)
        ->and($events)->toBe([
            'deploy-target',
            'publish-target',
            'remove-source',
            'withdraw-source',
        ]);
});

it('queues a safe move from the Node page', function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $cluster = CreateNodeCluster::run($team, $user, 'UI move mesh');
    $source = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id, 'name' => 'Source Node']);
    $target = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id, 'name' => 'Target Node']);
    AssignNodeToCluster::run($cluster, $source);
    AssignNodeToCluster::run($cluster, $target);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $source->workloads()->attach($workload);
    NodeWorkloadRevision::factory()->create(['node_workload_id' => $workload->id]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $source->uuid])
        ->assertSee('Move to Node')
        ->assertSee('Target Node')
        ->set('moveTargets.'.$workload->uuid, $target->uuid)
        ->call('moveWorkload', $workload->uuid)
        ->assertDispatched('success', 'Workload move queued. The source stays active until the target is ready.');

    $operation = $source->operations()->where('command_type', 'workload.move.v1')->firstOrFail();
    expect($operation->request['target_node_uuid'])->toBe($target->uuid);
    Queue::assertPushed(MoveNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});
