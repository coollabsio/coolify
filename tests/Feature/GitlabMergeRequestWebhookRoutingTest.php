<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createGitlabMergeRequestWebhookApplication(array $overrides = []): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create(array_merge([
        'name' => 'gitlab-mr-webhook-app',
        'git_repository' => 'https://gitlab.com/test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $overrides));
}

function createGitlabMergeRequestWebhookSource(): GitlabApp
{
    return GitlabApp::create([
        'name' => 'gitlab-app-webhook-test',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'webhook_token' => 'test-webhook-token',
        'team_id' => Team::factory()->create()->id,
    ]);
}

function gitlabMergeRequestPayload(string $action, string $targetBranch): array
{
    return [
        'object_kind' => 'merge_request',
        'project' => [
            'id' => 987654321,
            'path_with_namespace' => 'test-org/test-repo',
        ],
        'object_attributes' => [
            'action' => $action,
            'iid' => 42,
            'url' => 'https://gitlab.com/test-org/test-repo/-/merge_requests/42',
            'title' => 'Stacked change',
            'source_branch' => 'feature/child',
            'target_branch' => $targetBranch,
            'source_project_id' => 987654321,
            'target_project_id' => 987654321,
            'last_commit' => [
                'id' => 'abc123',
                'message' => 'Stacked change',
            ],
        ],
    ];
}

function expectGitlabPreviewCleanup(Application $application): void
{
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://gitlab.com/test-org/test-repo/-/merge_requests/42',
        'git_type' => 'gitlab',
    ]);

    CleanupPreviewDeployment::shouldRun()
        ->once()
        ->withArgs(fn (Application $cleanedApplication, int $pullRequestId, ApplicationPreview $cleanedPreview): bool => $cleanedApplication->is($application)
            && $pullRequestId === 42
            && $cleanedPreview->is($preview))
        ->andReturn([
            'cancelled_deployments' => 0,
            'killed_containers' => 0,
            'status' => 'success',
        ]);
}

it('cleans up the preview of a closed GitLab App merge request after the target branch changes', function (string $action) {
    $gitlabApp = createGitlabMergeRequestWebhookSource();
    $application = createGitlabMergeRequestWebhookApplication([
        'repository_project_id' => 987654321,
        'source_id' => $gitlabApp->id,
        'source_type' => GitlabApp::class,
    ]);
    expectGitlabPreviewCleanup($application);

    $response = $this->postJson('/webhooks/source/gitlab/events', gitlabMergeRequestPayload($action, 'feature/parent'), [
        'X-Gitlab-Token' => 'test-webhook-token',
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('Preview deployment closed.');
})->with(['close', 'merge']);

it('cleans up the preview of a closed manual merge request after the target branch changes', function (string $action) {
    $application = createGitlabMergeRequestWebhookApplication();
    expectGitlabPreviewCleanup($application);

    $response = $this->postJson('/webhooks/source/gitlab/events/manual', gitlabMergeRequestPayload($action, 'feature/parent'), [
        'X-Gitlab-Event' => 'Merge Request Hook',
        'X-Gitlab-Token' => $application->manual_webhook_secret_gitlab,
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('Preview deployment closed.');
})->with(['close', 'merge']);

it('continues to filter opened merge requests by target branch', function (string $endpoint) {
    Queue::fake();

    if ($endpoint === 'app') {
        $gitlabApp = createGitlabMergeRequestWebhookSource();
        $application = createGitlabMergeRequestWebhookApplication([
            'repository_project_id' => 987654321,
            'source_id' => $gitlabApp->id,
            'source_type' => GitlabApp::class,
        ]);
        $application->settings->update(['is_preview_deployments_enabled' => true]);

        $response = $this->postJson('/webhooks/source/gitlab/events', gitlabMergeRequestPayload('open', 'feature/parent'), [
            'X-Gitlab-Token' => 'test-webhook-token',
        ]);
    } else {
        $application = createGitlabMergeRequestWebhookApplication();
        $application->settings->update(['is_preview_deployments_enabled' => true]);

        $response = $this->postJson('/webhooks/source/gitlab/events/manual', gitlabMergeRequestPayload('open', 'feature/parent'), [
            'X-Gitlab-Event' => 'Merge Request Hook',
            'X-Gitlab-Token' => $application->manual_webhook_secret_gitlab,
        ]);
    }

    $response->assertOk();
    expect(ApplicationPreview::where('application_id', $application->id)->exists())->toBeFalse();
})->with(['app', 'manual']);
