<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createGiteaPullRequestWebhookApplication(): Application
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

    return Application::create([
        'name' => 'gitea-pr-webhook-app',
        'git_repository' => 'https://gitea.example.com/test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function sendGiteaPullRequestWebhook(Application $application, string $action, string $baseBranch)
{
    $body = json_encode([
        'action' => $action,
        'number' => 42,
        'repository' => [
            'id' => 987654321,
            'full_name' => 'test-org/test-repo',
        ],
        'pull_request' => [
            'html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/42',
            'title' => 'Stacked change',
            'head' => [
                'ref' => 'feature/child',
                'sha' => 'abc123',
                'repo' => ['id' => 987654321],
            ],
            'base' => [
                'ref' => $baseBranch,
                'repo' => ['id' => 987654321],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], [
        'HTTP_X-Gitea-Event' => 'pull_request',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_gitea),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('cleans up the preview of a closed pull request after the base branch changes', function () {
    $application = createGiteaPullRequestWebhookApplication();
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/42',
        'git_type' => 'gitea',
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

    $response = sendGiteaPullRequestWebhook($application, 'closed', 'feature/parent');

    $response->assertOk();
    expect($response->getContent())->toContain('Preview deployment closed.');
});

it('continues to filter opened pull requests by base branch', function () {
    Queue::fake();
    $application = createGiteaPullRequestWebhookApplication();
    $application->settings->update(['is_preview_deployments_enabled' => true]);

    $response = sendGiteaPullRequestWebhook($application, 'opened', 'feature/parent');

    $response->assertOk();
    expect(ApplicationPreview::where('application_id', $application->id)->exists())->toBeFalse();
});
