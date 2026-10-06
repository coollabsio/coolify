<?php

use App\Enums\NodeContainerManagementState;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Jobs\ManageNodeWorkloadJob;
use App\Livewire\Node\Show;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $this->cluster->id,
        'name' => 'worker-a',
        'metadata' => ['container_inventory_observed_at' => now()->toIso8601String()],
    ]);
    $this->otherNode = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $this->cluster->id,
        'name' => 'worker-b',
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->workload = applicationsPageWorkload($this, 'Example App', 'running');
});

function applicationsPageWorkload(object $test, string $name, ?string $containerState): NodeWorkload
{
    $workload = NodeWorkload::factory()->create([
        'team_id' => $test->team->id,
        'name' => $name,
        'project_id' => $test->project->id,
        'environment_id' => $test->environment->id,
        'internal_dns_name' => str($name)->slug()->toString(),
        'domains' => [str($name)->slug().'.example.com'],
    ]);
    $test->node->workloads()->attach($workload);
    $revision = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $workload->id,
        'image' => 'docker.io/library/nginx:1.27',
    ]);
    if ($containerState !== null) {
        NodeContainer::factory()->create([
            'node_id' => $test->node->id,
            'node_workload_id' => $workload->id,
            'node_workload_revision_id' => $revision->id,
            'state' => $containerState,
            'is_managed' => true,
            'management_state' => NodeContainerManagementState::MANAGED,
        ]);
    }

    return $workload;
}

function applicationsPage(object $test): mixed
{
    return Livewire::test(Show::class, ['node_uuid' => $test->node->uuid, 'section' => 'workloads']);
}

it('shows the Links dropdown instead of the image and internal hostname lines', function () {
    applicationsPage($this)
        ->assertSee('Example App')
        ->assertSee('Running')
        ->assertSeeHtml('title="Open cluster application links"')
        ->assertSee('http://example-app.example.com')
        ->assertSee('example-app.default.coolify.internal')
        ->assertDontSee('docker.io/library/nginx:1.27')
        ->assertDontSeeHtml('Copy internal hostname')
        ->assertDontSee('Recent activity');
});

it('labels the refresh button and has no remove button on a row', function () {
    applicationsPage($this)
        ->assertSeeHtml('wire:click="refreshWorkloads"')
        ->assertSee('Refresh')
        ->assertDontSeeHtml('aria-label="Remove"')
        ->assertDontSeeHtml('title="Remove"');
});

it('offers deploy with restart, stop, and move in the menu of a running application', function () {
    $revision = $this->workload->revisions()->firstOrFail();
    $prefix = 'node-workload-'.$this->workload->uuid;

    applicationsPage($this)
        ->assertSee("deployRevision('{$revision->uuid}')")
        ->assertSeeHtml("{$prefix}-restart-trigger")
        ->assertSeeHtml("{$prefix}-stop-trigger")
        ->assertSeeHtml("{$prefix}-move-trigger")
        ->assertSee('Move to another server…')
        ->assertSee('Confirm Application Stopping?')
        ->assertSee('Confirm Application Restart?')
        ->assertSeeHtml("manageWorkload(stop, {$this->workload->uuid})")
        ->assertSeeHtml("manageWorkload(restart, {$this->workload->uuid})")
        ->assertSee('worker-b')
        ->assertDontSee('Remove container')
        ->assertDontSee('Redeploy');
});

it('offers deploy with remove container and move in the menu of a stopped application', function () {
    $this->workload->containers()->update(['state' => 'exited']);

    applicationsPage($this)
        ->assertSee('Stopped')
        ->assertSee('Remove container')
        ->assertSee('Confirm Container Removal?')
        ->assertSeeHtml("manageWorkload(remove, {$this->workload->uuid})")
        ->assertSee('Move to another server…')
        ->assertDontSee('Confirm Application Stopping?')
        ->assertDontSeeHtml("manageWorkload(stop, {$this->workload->uuid})");
});

it('hides remove container when no container exists on this server', function () {
    $this->workload->containers()->delete();

    applicationsPage($this)
        ->assertSee('Move to another server…')
        ->assertDontSee('Remove container');
});

it('keys rows and confirmations by the workload uuid', function () {
    $html = applicationsPage($this)->html();

    expect($html)->toContain('wire:key="node-workload-'.$this->workload->uuid.'"')
        ->and($html)->toContain('node-workload-confirmations-'.$this->workload->uuid.'-running');
});

it('stops, restarts, and removes the container on this server only', function (string $action) {
    $this->otherNode->workloads()->attach($this->workload);
    Queue::fake();

    applicationsPage($this)
        ->call('manageWorkload', $action, $this->workload->uuid)
        ->assertHasNoErrors();

    $operation = NodeOperation::query()->where('node_workload_id', $this->workload->id)->sole();
    expect($operation->node_id)->toBe($this->node->id)
        ->and($operation->request['action'])->toBe($action);
    Queue::assertPushed(ManageNodeWorkloadJob::class, 1);
})->with(['stop', 'restart', 'remove']);

it('deploys on this server only', function () {
    $this->otherNode->workloads()->attach($this->workload);
    $revision = $this->workload->revisions()->firstOrFail();
    $this->node->update([
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => [
            ...$this->node->metadata,
            'cpu_usage_percent' => 10,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 100,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 900,
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    Queue::fake();

    applicationsPage($this)->call('deployRevision', $revision->uuid);

    expect(NodeOperation::query()->where('node_workload_id', $this->workload->id)->pluck('node_id')->all())
        ->toBe([$this->node->id]);
    Queue::assertPushed(DeployNodeWorkloadJob::class, 1);
});

it('rejects a confirmation for an application on another server or of another team', function () {
    $elsewhere = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->otherNode->workloads()->attach($elsewhere);
    NodeWorkloadRevision::factory()->create(['node_workload_id' => $elsewhere->id]);
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $foreign = NodeWorkload::factory()->create(['team_id' => $foreignTeam->id]);
    NodeWorkloadRevision::factory()->create(['node_workload_id' => $foreign->id]);
    Queue::fake();

    applicationsPage($this)->call('manageWorkload', 'stop', $elsewhere->uuid)->assertNotFound();
    applicationsPage($this)->call('manageWorkload', 'stop', $foreign->uuid)->assertNotFound();
    applicationsPage($this)->call('manageWorkload', 'restart', 'not-a-workload')->assertNotFound();

    expect(NodeOperation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('shows no actions to members and rejects their lifecycle calls', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    Queue::fake();

    applicationsPage($this)
        ->assertSee('Example App')
        ->assertSeeHtml('title="Open cluster application links"')
        ->assertDontSeeHtml('deployRevision(')
        ->assertDontSeeHtml('manageWorkload(')
        ->assertDontSee('Move to another server…')
        ->call('manageWorkload', 'stop', $this->workload->uuid);

    expect(NodeOperation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('shows the latest operation of an application on this server', function (array $attributes, string $text, string $toneClass) {
    $revision = $this->workload->revisions()->firstOrFail();
    NodeOperation::factory()->create([
        'node_id' => $this->otherNode->id,
        'node_workload_id' => $this->workload->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::FAILED,
    ]);
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now()->subDays(3),
    ]);
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'node_workload_revision_id' => $revision->id,
        ...$attributes,
    ]);

    $html = applicationsPage($this)->assertSee($text)->html();

    preg_match('/<p data-node-workload-activity class="([^"]*)"/', $html, $matches);
    expect($matches[1] ?? '')->toContain($toneClass);
})->with([
    'deployed' => [['command_type' => 'workload.deploy.v1', 'status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()->subHours(15)], 'Deployed 15 hours ago', 'text-neutral-500'],
    'stopped' => [['command_type' => 'workload.lifecycle.v1', 'request' => ['action' => 'stop'], 'status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()->subMinutes(2)], 'Stopped 2 minutes ago', 'text-neutral-500'],
    'restart queued' => [['command_type' => 'workload.lifecycle.v1', 'request' => ['action' => 'restart'], 'status' => NodeOperationStatus::QUEUED], 'Restart queued', 'text-neutral-700'],
    'deploy running' => [['command_type' => 'workload.deploy.v1', 'status' => NodeOperationStatus::RUNNING, 'started_at' => now()->subMinutes(1)], 'Deploy started 1 minute ago', 'text-neutral-700'],
    'container removed' => [['command_type' => 'workload.lifecycle.v1', 'request' => ['action' => 'remove'], 'status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()->subMinutes(5)], 'Container removed 5 minutes ago', 'text-neutral-500'],
    'move failed' => [['command_type' => 'workload.move.v1', 'status' => NodeOperationStatus::FAILED, 'completed_at' => now()->subHour()], 'Move failed 1 hour ago', 'text-error'],
    'deploy timed out' => [['command_type' => 'workload.deploy.v1', 'status' => NodeOperationStatus::TIMED_OUT, 'completed_at' => now()->subHour()], 'Deploy timed out 1 hour ago', 'text-error'],
    'resources updated' => [['command_type' => 'workload.resources.v1', 'status' => NodeOperationStatus::SUCCEEDED, 'completed_at' => now()->subHour()], 'Resources updated 1 hour ago', 'text-neutral-500'],
]);

it('links the latest deployment to its deployment page', function () {
    $operation = NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'command_type' => 'workload.deploy.v1',
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now()->subHours(2),
    ]);

    applicationsPage($this)
        ->assertSee('Deployed 2 hours ago')
        ->assertSee(route('project.cluster-application.deployment.show', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'workload_uuid' => $this->workload->uuid,
            'deployment_uuid' => $operation->uuid,
        ]), false);
});

it('does not link lifecycle operations to a deployment page', function () {
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'command_type' => 'workload.lifecycle.v1',
        'request' => ['action' => 'stop'],
        'status' => NodeOperationStatus::SUCCEEDED,
        'completed_at' => now(),
    ]);

    applicationsPage($this)
        ->assertSee('Stopped')
        ->assertDontSeeHtml('/deployment/');
});

it('shows a placeholder when an application has no activity on this server', function () {
    applicationsPage($this)->assertSee('No activity on this server yet');
});

it('renders the rows with a fixed number of queries', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        applicationsPage($this);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $addApplication = function (int $index): void {
        $workload = applicationsPageWorkload($this, "App {$index}", $index % 2 === 0 ? 'running' : 'exited');
        NodeOperation::factory()->count(2)->create([
            'node_id' => $this->node->id,
            'node_workload_id' => $workload->id,
            'status' => NodeOperationStatus::SUCCEEDED,
        ]);
    };
    $addApplication(1);
    $countQueries();
    $queriesForTwo = $countQueries();

    foreach (range(2, 11) as $index) {
        $addApplication($index);
    }
    $queriesForTwelve = $countQueries();

    expect($queriesForTwelve)->toBe($queriesForTwo);
});
