<?php

use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Livewire\Node\Activity;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $this->user = User::factory()->create(['name' => 'Ada Owner']);
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $cluster->id,
        'name' => 'worker-a',
    ]);
    $this->target = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $cluster->id,
        'name' => 'worker-b',
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->routeParameters = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
    ];
    $this->api = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Api Service',
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);
    $this->web = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Web Frontend',
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);
});

function activityOperation(object $test, array $attributes = []): NodeOperation
{
    return NodeOperation::factory()->create([
        'node_id' => $test->node->id,
        'node_workload_id' => $test->api->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::SUCCEEDED,
        ...$attributes,
    ]);
}

function activityPage(object $test): mixed
{
    return Livewire::test(Activity::class, ['node_uuid' => $test->node->uuid]);
}

function activityRowCount(mixed $component): int
{
    return substr_count($component->html(), 'data-node-activity-row');
}

it('renders the Activity page with the server sidebar', function () {
    activityOperation($this);

    $this->get(route('node.activity', $this->node->uuid))
        ->assertSuccessful()
        ->assertSee('Activity')
        ->assertSee('Api Service')
        ->assertSee(route('node.activity', $this->node->uuid), false)
        ->assertSee('menu-item-active', false);
});

it('describes each operation in readable columns', function () {
    $deploy = activityOperation($this, ['requested_by_id' => $this->user->id]);
    activityOperation($this, [
        'command_type' => 'workload.lifecycle.v1',
        'request' => ['action' => 'restart'],
        'node_workload_id' => $this->web->id,
    ]);
    activityOperation($this, [
        'command_type' => 'workload.move.v1',
        'request' => ['target_node_uuid' => $this->target->uuid],
        'status' => NodeOperationStatus::FAILED,
    ]);
    activityOperation($this, ['command_type' => 'sentinel.upgrade.v1', 'node_workload_id' => null]);

    activityPage($this)
        ->assertSee('Deploy')
        ->assertSee('Restart')
        ->assertSee('Move to worker-b')
        ->assertSee('Upgrade Sentinel')
        ->assertSee('Success')
        ->assertSee('Failed')
        ->assertSee('Ada Owner')
        ->assertSee('Web Frontend')
        ->assertSee(route('project.cluster-application.show', [...$this->routeParameters, 'workload_uuid' => $this->web->uuid]), false)
        ->assertSee(route('project.cluster-application.deployment.show', [
            ...$this->routeParameters,
            'workload_uuid' => $this->api->uuid,
            'deployment_uuid' => $deploy->uuid,
        ]), false)
        ->assertDontSee('workload.lifecycle.v1');
});

it('paginates 25 operations per page', function () {
    foreach (range(1, 26) as $index) {
        activityOperation($this);
    }

    $component = activityPage($this);
    expect(activityRowCount($component))->toBe(25);
    $component->assertSee('1–25 of 26');

    $component->call('nextPage');
    expect(activityRowCount($component))->toBe(1);
    $component->assertSee('26–26 of 26')->assertSet('page', 2);

    $component->call('goToPage', 99)->assertSet('page', 2);
    $component->call('goToPage', 1);
    expect(activityRowCount($component))->toBe(25);
});

it('hides the pagination footer on a single page', function () {
    activityOperation($this);

    activityPage($this)->assertDontSee('1–1 of 1');
});

it('filters by status', function () {
    activityOperation($this, ['status' => NodeOperationStatus::SUCCEEDED]);
    activityOperation($this, ['status' => NodeOperationStatus::FAILED]);
    activityOperation($this, ['status' => NodeOperationStatus::TIMED_OUT]);
    activityOperation($this, ['status' => NodeOperationStatus::RUNNING]);
    activityOperation($this, ['status' => NodeOperationStatus::QUEUED]);
    activityOperation($this, ['status' => NodeOperationStatus::CANCELLED]);

    $component = activityPage($this);
    expect(activityRowCount($component))->toBe(6);

    $component->call('toggleStatusFilter', 'succeeded');
    expect(activityRowCount($component))->toBe(1);

    $component->call('toggleStatusFilter', 'succeeded')->call('toggleStatusFilter', 'failed');
    expect(activityRowCount($component))->toBe(2);

    $component->call('toggleStatusFilter', 'active');
    expect(activityRowCount($component))->toBe(4);

    $component->call('toggleStatusFilter', 'bogus')->assertSet('statusFilters', ['failed', 'active']);

    $component->call('clearFilters');
    expect(activityRowCount($component))->toBe(6);
});

it('filters by application and combines it with the status filter', function () {
    activityOperation($this, ['node_workload_id' => $this->api->id]);
    activityOperation($this, ['node_workload_id' => $this->api->id, 'status' => NodeOperationStatus::FAILED]);
    activityOperation($this, ['node_workload_id' => $this->web->id]);

    $component = activityPage($this)->call('toggleApplicationFilter', $this->web->uuid);
    expect(activityRowCount($component))->toBe(1);
    $component->assertSee('Web Frontend');

    $component->call('toggleApplicationFilter', $this->web->uuid)->call('toggleApplicationFilter', $this->api->uuid);
    expect(activityRowCount($component))->toBe(2);

    $component->call('toggleStatusFilter', 'failed');
    expect(activityRowCount($component))->toBe(1);
});

it('resets to the first page when a filter changes', function () {
    foreach (range(1, 26) as $index) {
        activityOperation($this);
    }

    activityPage($this)
        ->call('nextPage')
        ->assertSet('page', 2)
        ->call('toggleStatusFilter', 'succeeded')
        ->assertSet('page', 1);
});

it('ignores application filters of another team', function () {
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $foreign = NodeWorkload::factory()->create(['team_id' => $foreignTeam->id]);
    activityOperation($this);

    activityPage($this)
        ->call('toggleApplicationFilter', $foreign->uuid)
        ->assertSet('applicationFilters', []);
});

it('hides background operations unless they fail', function () {
    activityOperation($this, ['command_type' => 'system.ping.v1', 'node_workload_id' => null]);
    activityOperation($this, ['command_type' => 'network.firewall.inspect.v1', 'node_workload_id' => null, 'status' => NodeOperationStatus::RUNNING]);
    activityOperation($this, ['command_type' => 'container.list.v1', 'node_workload_id' => null, 'status' => NodeOperationStatus::FAILED]);

    $component = activityPage($this)
        ->assertSee('Refresh containers')
        ->assertDontSee('Ping Sentinel')
        ->assertDontSee('Inspect firewall');
    expect(activityRowCount($component))->toBe(1);
});

it('only lists operations of this server', function () {
    activityOperation($this, ['node_id' => $this->target->id]);

    activityPage($this)->assertSee('No activity yet');
});

it('shows an empty state for filters without results', function () {
    activityOperation($this);

    activityPage($this)
        ->call('toggleStatusFilter', 'failed')
        ->assertSee('No matching activity');
});

it('lets admins recover an uncertain operation', function () {
    $operation = activityOperation($this, ['status' => NodeOperationStatus::UNCERTAIN]);
    Queue::fake();

    activityPage($this)
        ->assertSee('Recover')
        ->call('retryOperation', $operation->uuid)
        ->assertDispatched('success', 'Operation recovery queued.');

    Queue::assertPushed(DeployNodeWorkloadJob::class, fn ($job) => $job->operationId === $operation->id);
});

it('is read-only for members', function () {
    $operation = activityOperation($this, ['status' => NodeOperationStatus::UNCERTAIN]);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    Queue::fake();

    activityPage($this)
        ->assertSee('Api Service')
        ->assertSee('Uncertain')
        ->assertDontSeeHtml('retryOperation(')
        ->call('retryOperation', $operation->uuid);

    Queue::assertNothingPushed();
});

it('returns 404 for a server of another team', function () {
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignTeam->id,
        'private_key_id' => $this->node->private_key_id,
    ]);

    $this->get(route('node.activity', $foreignNode->uuid))->assertNotFound();
});
