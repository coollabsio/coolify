<?php

use App\Actions\Node\CreateOperation;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    $team = Team::factory()->create();
    $this->key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->node = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $this->key->id]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $team->id, 'name' => 'Example App']);
    $this->node->workloads()->attach($this->workload);
    $configuration = [
        'command' => ['sleep', '3600'],
        'environment' => ['APP_ENV' => 'production'],
        'ports' => [[
            'host_ip' => '127.0.0.1',
            'host_port' => 18080,
            'container_port' => 8080,
            'protocol' => 'tcp',
        ]],
        'restart_policy' => 'unless-stopped',
    ];
    $this->revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/alpine:latest',
        'configuration' => $configuration,
        'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
    ]);
    $this->operation = CreateOperation::run(
        $this->node,
        'workload.deploy.v1',
        "deploy:{$this->node->uuid}:{$this->revision->uuid}",
        $this->workload,
        $this->revision,
        ['revision_uuid' => $this->revision->uuid, 'configuration_hash' => $this->revision->configuration_hash],
    );
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
});

it('deploys a revision with the durable operation UUID and refreshes inventory', function () {
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/v1/commands/workload.deploy')) {
            return Http::response([
                'command_id' => $this->operation->uuid,
                'observed_at_unix_ms' => 1_700_000_000_000,
                'runtime_id' => 'runtime-123',
                'name' => 'coolify-'.$this->workload->uuid.'-main',
                'image' => $this->revision->image,
            ]);
        }

        return Http::response([
            'command_id' => 'inventory-1',
            'observed_at_unix_ms' => 1_700_000_000_100,
            'containers' => [[
                'runtime_id' => 'runtime-123',
                'name' => 'coolify-'.$this->workload->uuid.'-main',
                'image' => $this->revision->image,
                'state' => 'running',
                'health_status' => null,
                'restart_count' => 0,
                'ports' => [],
                'labels' => [
                    'coolify.managed' => 'true',
                    'coolify.instance' => 'instance-test',
                    'coolify.workload' => $this->workload->uuid,
                    'coolify.revision' => $this->revision->uuid,
                    'coolify.component' => 'main',
                ],
            ]],
        ]);
    });

    (new DeployNodeWorkloadJob($this->operation->id))->handle();

    $operation = $this->operation->refresh();
    expect($operation->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->attempt_count)->toBe(1)
        ->and($operation->result['runtime_id'])->toBe('runtime-123')
        ->and($this->node->containers()->first()->node_workload_id)->toBe($this->workload->id);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/commands/workload.deploy')
        && $request['command_id'] === $operation->uuid
        && $request['environment'] === ['APP_ENV' => 'production']
        && $request['labels']['coolify.revision'] === $this->revision->uuid);
});

it('marks an explicit Sentinel failure as failed', function () {
    Http::fake(['*/v1/commands/workload.deploy' => Http::response('Sentinel command failed', 502)]);

    (new DeployNodeWorkloadJob($this->operation->id))->handle();

    expect($this->operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($this->operation->error)->toContain('502');
});

it('marks an unknown transport outcome as uncertain', function () {
    Http::fake(fn () => throw new ConnectionException('connection lost'));

    (new DeployNodeWorkloadJob($this->operation->id))->handle();

    expect($this->operation->refresh()->status)->toBe(NodeOperationStatus::UNCERTAIN)
        ->and($this->operation->error)->toBe('The deployment result is unknown.');
});

it('does not dispatch a completed operation again', function () {
    $this->operation->update(['status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()]);
    Http::preventStrayRequests();

    (new DeployNodeWorkloadJob($this->operation->id))->handle();

    Http::assertNothingSent();
});

it('queues an assigned revision from the Node page without storing environment values', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $node = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $this->key->id]);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $node->workloads()->attach($workload);
    $revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $workload->id,
        'configuration' => ['environment' => ['SECRET' => 'do-not-store']],
    ]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $node->uuid])
        ->assertSee('Workloads')
        ->assertSee('Unknown')
        ->assertSee('Deploy')
        ->call('deployRevision', $revision->uuid)
        ->assertDispatched('success');

    $operation = $node->operations()->firstOrFail();
    expect($operation->request)->toBe([
        'revision_uuid' => $revision->uuid,
        'configuration_hash' => $revision->configuration_hash,
    ]);
    Queue::assertPushed(
        DeployNodeWorkloadJob::class,
        fn ($job) => $job->operationId === $operation->id,
    );
});

it('queues an uncertain deployment again with the same operation identity', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $node = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $this->key->id]);
    $operation = NodeOperation::factory()->create([
        'node_id' => $node->id,
        'status' => NodeOperationStatus::UNCERTAIN,
        'command_type' => 'workload.deploy.v1',
    ]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $node->uuid])
        ->call('retryOperation', $operation->uuid)
        ->assertDispatched('success');

    Queue::assertPushed(
        DeployNodeWorkloadJob::class,
        fn ($job) => $job->operationId === $operation->id,
    );
});

it('cannot deploy a revision that is not assigned to the Node', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $user = User::factory()->create();
    $team = $user->teams()->firstOrFail();
    $node = Node::factory()->create(['team_id' => $team->id, 'private_key_id' => $this->key->id]);
    $workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $workload->id]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    Queue::fake();

    Livewire::test(Show::class, ['node_uuid' => $node->uuid])
        ->call('deployRevision', $revision->uuid);

    expect($node->operations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('records a queue failure before dispatch', function () {
    (new DeployNodeWorkloadJob($this->operation->id))->failed(new RuntimeException('worker stopped'));

    expect($this->operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($this->operation->error)->toBe('The deployment worker stopped before dispatch.');
});
