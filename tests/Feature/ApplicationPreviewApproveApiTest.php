<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ApplicationDeploymentJob::class]);
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->bearerToken = approveApiToken($this->user, $this->team, ['*']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->pendingCommit = str_repeat('a', 40);
    $this->preview = ApplicationPreview::create([
        'uuid' => (string) new Cuid2,
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'git_type' => 'github',
        'fqdn' => 'pr-42.example.com',
    ]);
    $this->preview->forceFill([
        'is_fork_pull_request' => true,
        'pending_approval_commit' => $this->pendingCommit,
    ])->save();
});

function approveApiToken(User $user, Team $team, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

function approveApiHeaders(string $bearerToken): array
{
    return ['Authorization' => 'Bearer '.$bearerToken, 'Content-Type' => 'application/json'];
}

test('the preview response shows the approval state', function () {
    $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->getJson("/api/v1/applications/{$this->application->uuid}/previews/42")
        ->assertOk()
        ->assertJsonPath('is_fork_pull_request', true)
        ->assertJsonPath('approval_status', 'waiting_for_approval')
        ->assertJsonPath('pending_approval_commit', $this->pendingCommit)
        ->assertJsonPath('approved_commit', null)
        ->assertJsonPath('approved_at', null);
});

test('approving the pending commit queues its deployment', function () {
    $response = $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => $this->pendingCommit])
        ->assertOk();

    $preview = $this->preview->fresh();
    expect($preview->approved_commit)->toBe($this->pendingCommit)
        ->and($preview->pending_approval_commit)->toBeNull()
        ->and($preview->approvalStatus())->toBe('approved');

    $deployment = $this->application->deployment_queue()->latest('id')->first();
    expect($response->json('deployment_uuid'))->toBe($deployment->deployment_uuid)
        ->and($response->json('message'))->toBeString()
        ->and($deployment->pull_request_id)->toBe(42)
        ->and($deployment->commit)->toBe($this->pendingCommit);
    Queue::assertPushed(ApplicationDeploymentJob::class);
});

test('a token without write and deploy abilities cannot approve', function (array $abilities) {
    $this->withHeaders(approveApiHeaders(approveApiToken($this->user, $this->team, $abilities)))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => $this->pendingCommit])
        ->assertForbidden();

    expect($this->preview->fresh()->pending_approval_commit)->toBe($this->pendingCommit);
})->with([
    'read' => [['read']],
    'write only' => [['write']],
    'deploy only' => [['deploy']],
]);

test('a team member cannot approve', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    $this->withHeaders(approveApiHeaders(approveApiToken($member, $this->team, ['*'])))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => $this->pendingCommit])
        ->assertForbidden();

    expect($this->preview->fresh()->pending_approval_commit)->toBe($this->pendingCommit);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('the application of another team is not found', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

    $this->withHeaders(approveApiHeaders(approveApiToken($otherUser, $otherTeam, ['*'])))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => $this->pendingCommit])
        ->assertNotFound();

    expect($this->preview->fresh()->pending_approval_commit)->toBe($this->pendingCommit);
});

test('an unknown preview is not found', function () {
    $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/7/approve", ['commit' => $this->pendingCommit])
        ->assertNotFound()
        ->assertJson(['message' => 'Preview not found.']);
});

test('a stale commit returns 409 and changes nothing', function () {
    $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => str_repeat('b', 40)])
        ->assertStatus(409);

    $preview = $this->preview->fresh();
    expect($preview->pending_approval_commit)->toBe($this->pendingCommit)
        ->and($preview->approved_commit)->toBeNull();
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
});

test('the commit must be a full SHA', function (mixed $commit) {
    $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->postJson("/api/v1/applications/{$this->application->uuid}/previews/42/approve", ['commit' => $commit])
        ->assertStatus(422)
        ->assertJsonValidationErrors('commit');

    expect($this->preview->fresh()->pending_approval_commit)->toBe($this->pendingCommit);
})->with([
    'missing' => [null],
    'short' => ['abc1234'],
    'not hex' => [str_repeat('z', 40)],
    'wrong length' => [str_repeat('a', 41)],
]);

test('the application update API sets is_pr_deployments_public_approval_required', function () {
    $this->withHeaders(approveApiHeaders($this->bearerToken))
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['is_pr_deployments_public_approval_required' => false])
        ->assertOk();

    expect($this->application->settings->fresh()->is_pr_deployments_public_approval_required)->toBeFalse();
});
