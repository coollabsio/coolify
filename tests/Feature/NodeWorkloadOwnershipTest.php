<?php

use App\Actions\Node\BuildContainerLabels;
use App\Actions\Node\ReconcileContainers;
use App\Enums\NodeContainerManagementState;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
    $this->workload->nodes()->attach($this->node);
});

function observedContainer(string $runtimeId, array $labels = []): array
{
    return [
        'runtime_id' => $runtimeId,
        'name' => 'web',
        'image' => 'ghcr.io/example/web:latest',
        'state' => 'running',
        'health_status' => 'healthy',
        'restart_count' => 0,
        'ports' => [['host' => 8080, 'container' => 80]],
        'labels' => $labels,
        'created_at' => '2026-09-12T18:00:00Z',
        'started_at' => '2026-09-12T18:00:01Z',
        'observed_at' => '2026-09-12T18:01:00Z',
    ];
}

it('creates the workload ownership schema and a stable installation identity', function () {
    $first = $this->settings->ensureInstanceUuid();

    expect(Schema::hasTable('node_workloads'))->toBeTrue()
        ->and(Schema::hasTable('node_workload_revisions'))->toBeTrue()
        ->and(Schema::hasTable('node_workload_nodes'))->toBeTrue()
        ->and(Schema::hasTable('node_containers'))->toBeTrue()
        ->and($first)->toBeUuid()
        ->and($this->settings->fresh()->ensureInstanceUuid())->toBe($first);
});

it('builds the standard labels for a managed workload revision', function () {
    $labels = BuildContainerLabels::run($this->workload, $this->revision, 'web');

    expect($labels)->toMatchArray([
        'coolify.managed' => 'true',
        'coolify.instance' => $this->settings->fresh()->ensureInstanceUuid(),
        'coolify.workload' => $this->workload->uuid,
        'coolify.revision' => $this->revision->uuid,
        'coolify.component' => 'web',
    ]);
});

it('rejects a revision from another workload when building labels', function () {
    $otherWorkload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);

    expect(fn () => BuildContainerLabels::run($otherWorkload, $this->revision, 'web'))
        ->toThrow(InvalidArgumentException::class);
});

it('reconciles and resolves a valid managed container', function () {
    $labels = BuildContainerLabels::run($this->workload, $this->revision, 'web');

    ReconcileContainers::run($this->node, [observedContainer('container-1', $labels)]);

    $container = $this->node->containers()->firstOrFail();
    expect($container->management_state)->toBe(NodeContainerManagementState::MANAGED)
        ->and($container->is_managed)->toBeTrue()
        ->and($container->workload->is($this->workload))->toBeTrue()
        ->and($container->revision->is($this->revision))->toBeTrue()
        ->and($container->labels)->toBe($labels);
});

it('classifies containers without Coolify labels as external', function () {
    ReconcileContainers::run($this->node, [observedContainer('external-1', ['vendor' => 'example'])]);

    $container = $this->node->containers()->firstOrFail();
    expect($container->management_state)->toBe(NodeContainerManagementState::EXTERNAL)
        ->and($container->is_managed)->toBeFalse()
        ->and($container->node_workload_id)->toBeNull()
        ->and($container->node_workload_revision_id)->toBeNull();
});

it('does not trust forged or incomplete Coolify labels', function (array $changes) {
    $labels = [...BuildContainerLabels::run($this->workload, $this->revision, 'web'), ...$changes];

    ReconcileContainers::run($this->node, [observedContainer('forged-1', $labels)]);

    $container = $this->node->containers()->firstOrFail();
    expect($container->management_state)->toBe(NodeContainerManagementState::UNRECOGNIZED)
        ->and($container->is_managed)->toBeFalse()
        ->and($container->node_workload_id)->toBeNull();
})->with([
    'foreign instance' => [['coolify.instance' => '00000000-0000-4000-8000-000000000000']],
    'unknown workload' => [['coolify.workload' => 'missing']],
    'unknown revision' => [['coolify.revision' => 'missing']],
    'false managed flag' => [['coolify.managed' => 'false']],
]);

it('requires the workload to be assigned to the reporting node', function () {
    $this->workload->nodes()->detach($this->node);
    $labels = BuildContainerLabels::run($this->workload, $this->revision, 'web');

    ReconcileContainers::run($this->node, [observedContainer('unassigned-1', $labels)]);

    expect($this->node->containers()->firstOrFail()->management_state)
        ->toBe(NodeContainerManagementState::UNRECOGNIZED);
});

it('updates current observations and removes containers missing from a complete snapshot', function () {
    ReconcileContainers::run($this->node, [
        observedContainer('keep-1'),
        observedContainer('remove-1'),
    ]);
    $updated = observedContainer('keep-1');
    $updated['state'] = 'exited';
    $updated['observed_at'] = '2026-09-12T18:02:00Z';

    ReconcileContainers::run($this->node, [$updated]);

    expect($this->node->containers()->count())->toBe(1)
        ->and($this->node->containers()->firstOrFail()->state)->toBe('exited')
        ->and($this->node->containers()->where('runtime_id', 'remove-1')->exists())->toBeFalse();
});

it('rejects invalid or duplicate runtime observations without changing stored state', function () {
    ReconcileContainers::run($this->node, [observedContainer('existing-1')]);

    expect(fn () => ReconcileContainers::run($this->node, [
        observedContainer('duplicate-1'),
        observedContainer('duplicate-1'),
    ]))->toThrow(ValidationException::class)
        ->and($this->node->containers()->pluck('runtime_id')->all())->toBe(['existing-1']);
});

it('authorizes workload and container reads only for their team', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach($this->team, ['role' => 'owner']);
    $outsider = User::factory()->create();
    ReconcileContainers::run($this->node, [observedContainer('policy-1')]);
    $container = $this->node->containers()->firstOrFail();

    expect($owner->can('view', $this->workload))->toBeTrue()
        ->and($owner->can('update', $this->workload))->toBeTrue()
        ->and($owner->can('view', $container))->toBeTrue()
        ->and($outsider->can('view', $this->workload))->toBeFalse()
        ->and($outsider->can('view', $container))->toBeFalse()
        ->and($owner->can('update', $container))->toBeFalse();
});
