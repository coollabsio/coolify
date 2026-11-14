<?php

use App\Enums\NodeOperationStatus;
use App\Jobs\DeployNodeWorkloadJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['is_api_enabled' => true]));
    setNodesEnabled(true);
    Queue::fake();
    config()->set('constants.flux.internal_url', 'http://flux:7080');
    config()->set('constants.flux.internal_token', 'test-token');

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'active']);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'node_cluster_id' => $cluster->id,
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
    $this->old = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/nginx:1.26',
        'configuration' => ['restart_policy' => 'unless-stopped'],
        'environment' => ['DATABASE_PASSWORD' => 'old-environment-secret'],
        'secrets' => ['token' => 'old-secret-file'],
    ]);
    $this->current = NodeWorkloadRevision::factory()->create([
        'node_workload_id' => $this->workload->id,
        'image' => 'docker.io/library/nginx:1.27',
        'configuration' => ['restart_policy' => 'unless-stopped'],
    ]);
    foreach ([$this->old, $this->current] as $revision) {
        NodeOperation::factory()->create(['node_id' => $this->node->id, 'node_workload_id' => $this->workload->id, 'node_workload_revision_id' => $revision->id, 'status' => NodeOperationStatus::SUCCEEDED]);
    }
});

function clusterApiHeaders(User $user, array $abilities, ?Team $team = null): array
{
    $team ??= test()->team;

    return ['Authorization' => 'Bearer '.$user->createToken('cluster-rollback-test', $abilities, teamId: $team->id)->plainTextToken, 'Content-Type' => 'application/json'];
}

/** @return array{0: User, 1: Team} The owner of another team. */
function clusterApiOtherTeamOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    return [$user, $team];
}

it('lists the revisions newest first without environment variables or secrets', function () {
    $response = $this->withHeaders(clusterApiHeaders($this->admin, ['read']))
        ->getJson("/api/v1/cluster-applications/{$this->workload->uuid}/revisions")
        ->assertOk();

    expect($response->json('*.uuid'))->toBe([$this->current->uuid, $this->old->uuid])
        ->and($response->json('*.current'))->toBe([true, false])
        ->and($response->json('*.can_roll_back'))->toBe([false, true])
        ->and($response->json('1.last_deployment.status'))->toBe('succeeded')
        ->and($response->json('1'))->not->toHaveKeys(['environment', 'secrets', 'configuration']);
    expect($response->getContent())->not->toContain('old-environment-secret')->not->toContain('old-secret-file');
});

it('offers no rollback to a revision that never deployed successfully', function () {
    NodeOperation::query()->where('node_workload_revision_id', $this->old->id)->update(['status' => NodeOperationStatus::FAILED]);

    $this->withHeaders(clusterApiHeaders($this->admin, ['read']))
        ->getJson("/api/v1/cluster-applications/{$this->workload->uuid}/revisions")
        ->assertOk()
        ->assertJsonPath('1.can_roll_back', false);
});

it('rolls back to an earlier revision and queues its deployment', function () {
    $response = $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid])
        ->assertOk()
        ->assertJsonPath('build_uuid', null);

    $rollback = $this->workload->revisions()->latest('id')->first();
    $operation = NodeOperation::query()->where('node_workload_revision_id', $rollback->id)->sole();
    expect($rollback->image)->toBe('docker.io/library/nginx:1.26')
        ->and($response->json('deployment_uuid'))->toBe($operation->uuid);
    Queue::assertPushed(DeployNodeWorkloadJob::class, fn (DeployNodeWorkloadJob $job): bool => $job->operationId === $operation->id);
});

it('needs the deploy ability to roll back', function () {
    $this->withHeaders(clusterApiHeaders($this->admin, ['read', 'write']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid])
        ->assertForbidden();

    expect($this->workload->revisions()->count())->toBe(2);
});

it('does not let a team member roll back', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->withHeaders(clusterApiHeaders($member, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid])
        ->assertForbidden();

    expect($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('hides the revisions of an application of another team', function () {
    [$other, $otherTeam] = clusterApiOtherTeamOwner();

    $this->withHeaders(clusterApiHeaders($other, ['read'], $otherTeam))
        ->getJson("/api/v1/cluster-applications/{$this->workload->uuid}/revisions")
        ->assertNotFound();
});

it('does not roll back an application of another team', function () {
    [$other, $otherTeam] = clusterApiOtherTeamOwner();

    $this->withHeaders(clusterApiHeaders($other, ['deploy'], $otherTeam))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid])
        ->assertNotFound();

    expect($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('rejects a revision of another application', function () {
    $otherWorkload = NodeWorkload::factory()->create(['team_id' => $this->team->id, 'project_id' => $this->project->id, 'environment_id' => $this->environment->id]);
    $foreign = NodeWorkloadRevision::factory()->create(['node_workload_id' => $otherWorkload->id, 'image' => 'docker.io/library/redis:7']);

    $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $foreign->uuid])
        ->assertNotFound();

    expect($this->workload->revisions()->count())->toBe(2);
});

it('explains why a rollback cannot run', function () {
    $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->current->uuid])
        ->assertStatus(400)
        ->assertJsonPath('message', 'This revision runs the current image. Deploy the application to apply other changes.');

    $this->node->update(['is_reachable' => false]);
    $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid])
        ->assertStatus(400)
        ->assertJsonPath('message', "Server {$this->node->name} is not reachable.");

    // A failed rollback leaves no new latest revision, which reconciliation would deploy later.
    expect($this->workload->revisions()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('validates the request body', function () {
    $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", ['revision_uuid' => $this->old->uuid, 'force' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['force']);
    $this->withHeaders(clusterApiHeaders($this->admin, ['deploy']))
        ->postJson("/api/v1/cluster-applications/{$this->workload->uuid}/rollback", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['revision_uuid']);
});
