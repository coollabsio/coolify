<?php

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\RestoreNodeDatabase;
use App\Enums\NodeDatabaseType;
use App\Enums\NodeOperationStatus;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    setNodesEnabled(true);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
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
    AssignNodeToCluster::run(CreateNodeCluster::run($team, $user, 'Production'), $this->node);
    Queue::fake();
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
});

/**
 * A deployed cluster database whose container runs with this start command.
 *
 * @param  list<string>  $command
 */
function deployedNodeDatabase(Node $node, NodeDatabaseType $type, array $command): NodeWorkload
{
    $workload = NodeWorkload::factory()->create(['team_id' => $node->team_id, 'database_type' => $type]);
    $node->workloads()->attach($workload);
    $workload->createRevision('docker.io/library/redis:8', array_filter(['command' => $command, 'restart_policy' => 'unless-stopped']));
    // The revision of a deployment has the environment of the workload.
    $revision = PrepareNodeWorkloadRevision::run($workload);
    NodeOperation::factory()->create([
        'node_id' => $node->id,
        'node_workload_id' => $workload->id,
        'node_workload_revision_id' => $revision->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::SUCCEEDED,
    ]);

    return $workload;
}

/** Flux accepts the deployment and then reports the container of the latest revision as running. */
function fakeFluxDeploymentOf(NodeWorkload $workload): void
{
    Http::fake(function ($request) use ($workload) {
        $revision = $workload->revisions()->latest('id')->first();
        $container = ['runtime_id' => 'runtime-new', 'name' => 'coolify-'.$workload->uuid.'-main', 'image' => $revision->image];
        if (str_ends_with($request->url(), '/v1/commands/workload.deploy')) {
            return Http::response(['command_id' => $request['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000, ...$container]);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => 1_700_000_000_100,
            'containers' => [[
                ...$container,
                'state' => 'running',
                'health_status' => null,
                'restart_count' => 0,
                'ports' => [],
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.instance' => 'instance-test',
                    'coolify.workload' => $workload->uuid,
                    'coolify.revision' => $revision->uuid,
                    'coolify.component' => 'main',
                ],
            ]],
        ]);
    });
}

const EARLIER_REDIS_START_COMMAND = ['sh', '-c', 'exec redis-server --requirepass "$REDIS_PASSWORD" --appendonly yes'];

it('deploys a Redis database from before dump backups again with the current start command before a restore', function () {
    $workload = deployedNodeDatabase($this->node, NodeDatabaseType::REDIS, EARLIER_REDIS_START_COMMAND);
    fakeFluxDeploymentOf($workload);
    $revisions = $workload->revisions()->count();

    RestoreNodeDatabase::ensureRestorableStartCommand($workload, $this->node, now()->addMinutes(10));

    $deployed = $workload->deployedRevision();
    expect($workload->revisions()->count())->toBe($revisions + 1)
        ->and($deployed->configuration['command'])->toBe(NodeDatabaseType::REDIS->startCommand())
        ->and($deployed->configuration['restart_policy'])->toBe('unless-stopped')
        ->and($deployed->image)->toBe('docker.io/library/redis:8');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/commands/workload.deploy')
        && in_array('exec redis-server --requirepass "$REDIS_PASSWORD" --appendonly yes --enable-debug-command local', $request['command'], true)
        && $request['pull_policy'] === 'missing');
});

it('does not deploy a database again when its start command can restore a dump', function (NodeDatabaseType $type) {
    $workload = deployedNodeDatabase($this->node, $type, $type->startCommand());
    Http::fake();
    $revisions = $workload->revisions()->count();

    RestoreNodeDatabase::ensureRestorableStartCommand($workload, $this->node, now()->addMinutes(10));

    expect($workload->revisions()->count())->toBe($revisions);
    Http::assertNothingSent();
})->with([
    'Redis with the current start command' => [NodeDatabaseType::REDIS],
    'KeyDB' => [NodeDatabaseType::KEYDB],
    'PostgreSQL' => [NodeDatabaseType::POSTGRESQL],
]);

it('keeps a Redis start command that the user changed and tells how to allow a restore', function () {
    $workload = deployedNodeDatabase($this->node, NodeDatabaseType::REDIS, ['redis-server', '--maxmemory', '1gb']);
    Http::fake();
    $revisions = $workload->revisions()->count();

    expect(fn () => RestoreNodeDatabase::ensureRestorableStartCommand($workload, $this->node, now()->addMinutes(10)))
        ->toThrow(RuntimeException::class, 'Add "--enable-debug-command local" to the start command, redeploy the database, and restore again.');

    expect($workload->revisions()->count())->toBe($revisions);
    Http::assertNothingSent();
});

it('fails the restore with a clear reason when the database cannot be deployed again', function () {
    $workload = deployedNodeDatabase($this->node, NodeDatabaseType::REDIS, EARLIER_REDIS_START_COMMAND);
    Http::fake(fn () => Http::response('podman failed', 502));

    expect(fn () => RestoreNodeDatabase::ensureRestorableStartCommand($workload, $this->node, now()->addMinutes(10)))
        ->toThrow(RuntimeException::class, 'The database could not be deployed again with the start command that a restore needs.');

    expect($workload->deployedRevision()->configuration['command'])->toBe(EARLIER_REDIS_START_COMMAND);
});
