<?php

use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadSourceType;
use App\Jobs\DeployDockerfileWorkloadJob;
use App\Jobs\DeployNodeWorkloadJob;
use App\Livewire\Project\ClusterApplication\Show;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    setNodesEnabled(true);
    Queue::fake();
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->team->update(['show_boarding' => false]);
    Cache::flush();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'active']);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $this->cluster->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
        'is_usable' => true,
        'is_reachable' => true,
        'metadata' => ['cpu_usage_percent' => 10, 'memory_bytes' => 1_000, 'memory_used_bytes' => 100, 'disk_total_bytes' => 1_000, 'disk_available_bytes' => 900, 'collected_at' => now()->toIso8601String()],
    ]);
    $this->workload = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->project->id,
        'environment_id' => $this->environment->id,
    ]);
    $this->node->workloads()->attach($this->workload);
    $this->old = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id, 'image' => 'docker.io/library/nginx:1.26', 'configuration' => ['restart_policy' => 'unless-stopped']]);
    $this->current = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id, 'image' => 'docker.io/library/nginx:1.27', 'configuration' => ['restart_policy' => 'unless-stopped', 'command' => ['nginx', '-g', 'daemon off;']]]);
    $this->workload->environment_variables()->create(['key' => 'GREETING', 'value' => 'edited since', 'is_runtime' => true]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function rollbackPage(object $test): array
{
    return [
        'project_uuid' => $test->project->uuid,
        'environment_uuid' => $test->environment->uuid,
        'workload_uuid' => $test->workload->uuid,
    ];
}

/** Turns the workload into a Git application whose revisions are cluster registry images with a registry Node. */
function rollbackGitWorkload(object $test, ?string $commit = 'c0ffee1'): void
{
    $test->workload->update(['source_type' => NodeWorkloadSourceType::DOCKERFILE, 'git_repository' => 'https://github.com/acme/app', 'git_branch' => 'main']);
    $test->old->update(['image' => NodeRegistry::HOST.'/'.$test->workload->uuid.'@sha256:'.str_repeat('a', 64)]);
    $test->current->update(['image' => NodeRegistry::HOST.'/'.$test->workload->uuid.'@sha256:'.str_repeat('b', 64)]);
    $test->workload->builds()->create([
        'source_type' => NodeWorkloadSourceType::DOCKERFILE,
        'git_repository' => 'https://github.com/acme/old-app',
        'git_branch' => 'release',
        'commit_sha' => $commit,
        'dockerfile_location' => '/docker/Dockerfile',
        'image_digest' => 'sha256:'.str_repeat('a', 64),
        'node_workload_revision_id' => $test->old->id,
        'status' => NodeBuildStatus::SUCCEEDED,
    ]);
    NodeRegistry::query()->create([
        'node_cluster_id' => $test->cluster->id,
        'node_id' => $test->node->id,
        'ca_certificate_pem' => 'ca',
        'ca_private_key_pem' => 'key',
        'push_password' => 'push',
        'pull_password' => 'pull',
        'htpasswd' => 'users',
    ]);
    $test->node->update(['sentinel_capabilities' => ['workload.deploy.v1', NodeRegistry::INSPECT_CAPABILITY]]);
    Cache::put($test->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String()]);
}

/** @param  list<string>  $digests */
function fakeRegistryDigests(object $test, array $digests): void
{
    Http::fake(['flux:7080/v1/commands/registry.inspect' => Http::response([
        'registry_running' => true,
        'healthy' => true,
        'repositories' => [['name' => $test->workload->uuid, 'tags' => array_map(fn (string $digest): array => ['tag' => 'build', 'digest' => $digest], $digests)]],
    ])]);
}

it('rolls back to the image of an earlier revision with the current settings and environment', function () {
    Livewire::test(Show::class, rollbackPage($this))
        ->call('rollback', $this->old->uuid)
        ->assertHasNoErrors()
        ->assertRedirectContains('/deployments/');

    $rollback = $this->workload->revisions()->latest('id')->first();
    $operation = NodeOperation::query()->where('node_workload_id', $this->workload->id)->sole();
    expect($rollback->id)->not->toBe($this->old->id)
        ->and($rollback->image)->toBe('docker.io/library/nginx:1.26')
        ->and($rollback->configuration['command'])->toBe(['nginx', '-g', 'daemon off;'])
        ->and($rollback->environment['GREETING'])->toBe('edited since')
        ->and($operation->node_workload_revision_id)->toBe($rollback->id)
        ->and(data_get($operation->request, 'pull_policy'))->toBe('missing');
    Queue::assertPushed(DeployNodeWorkloadJob::class, fn (DeployNodeWorkloadJob $job): bool => $job->operationId === $operation->id);
});

it('offers a rollback only on earlier successful deployments', function () {
    foreach ([[$this->old, NodeOperationStatus::SUCCEEDED], [$this->current, NodeOperationStatus::SUCCEEDED]] as [$revision, $status]) {
        NodeOperation::factory()->create(['node_id' => $this->node->id, 'node_workload_id' => $this->workload->id, 'node_workload_revision_id' => $revision->id, 'status' => $status]);
    }

    // The confirmation modal writes its submit action as JSON.
    $this->get(route('project.cluster-application.deployments', rollbackPage($this)))
        ->assertOk()
        ->assertSee("rollback(\\u0027{$this->old->uuid}\\u0027)", false)
        ->assertDontSee($this->current->uuid.'\\u0027', false);
});

it('denies a rollback to a member', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Show::class, rollbackPage($this))
        ->call('rollback', $this->old->uuid)
        ->assertForbidden();

    expect($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('hides the application of another team', function () {
    $other = User::factory()->create();
    $otherTeam = $other->teams()->firstOrFail();
    $otherTeam->update(['show_boarding' => false]);
    Cache::flush();
    $this->actingAs($other);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('project.cluster-application.deployments', rollbackPage($this)))->assertNotFound();
});

it('rejects a revision of another workload', function () {
    $otherWorkload = NodeWorkload::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id, 'environment_id' => $this->environment->id]);
    $foreign = NodeWorkloadRevision::factory()->create(['node_workload_id' => $otherWorkload->id, 'image' => 'docker.io/library/redis:7']);

    expect(fn () => Livewire::test(Show::class, rollbackPage($this))->call('rollback', $foreign->uuid))
        ->toThrow(ModelNotFoundException::class);

    expect($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('deploys a cluster registry image that the registry still has', function () {
    rollbackGitWorkload($this);
    fakeRegistryDigests($this, ['sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64)]);

    Livewire::test(Show::class, rollbackPage($this))->call('rollback', $this->old->uuid)->assertRedirectContains('/deployments/');

    expect($this->workload->revisions()->latest('id')->value('image'))->toBe($this->old->image)
        ->and($this->workload->builds()->count())->toBe(1);
    Queue::assertPushed(DeployNodeWorkloadJob::class);
});

it('builds the same commit again when the registry no longer has the image', function () {
    rollbackGitWorkload($this);
    fakeRegistryDigests($this, ['sha256:'.str_repeat('b', 64)]);

    Livewire::test(Show::class, rollbackPage($this))->call('rollback', $this->old->uuid)->assertRedirectContains('/builds/');

    $build = $this->workload->builds()->latest('id')->first();
    expect($build->status)->toBe(NodeBuildStatus::QUEUED)
        ->and($build->only(['git_repository', 'git_branch', 'commit_sha', 'dockerfile_location']))->toBe([
            'git_repository' => 'https://github.com/acme/old-app',
            'git_branch' => 'release',
            'commit_sha' => 'c0ffee1',
            'dockerfile_location' => '/docker/Dockerfile',
        ])
        ->and($this->workload->revisions()->count())->toBe(2);
    Queue::assertPushed(DeployDockerfileWorkloadJob::class, fn (DeployDockerfileWorkloadJob $job): bool => $job->buildId === $build->id);
    Queue::assertNotPushed(DeployNodeWorkloadJob::class);
});

it('fails with a clear error when the image is gone and the commit is unknown', function () {
    rollbackGitWorkload($this, commit: null);
    fakeRegistryDigests($this, []);

    Livewire::test(Show::class, rollbackPage($this))
        ->call('rollback', $this->old->uuid)
        ->assertDispatched('error', 'The image of this revision is no longer in the cluster registry, and Coolify cannot build it again because it does not know the commit that it was built from.')
        ->assertNoRedirect();

    expect($this->workload->builds()->count())->toBe(1)
        ->and($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});
