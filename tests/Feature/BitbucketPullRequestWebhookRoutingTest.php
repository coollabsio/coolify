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

function createBitbucketPullRequestWebhookApplication(): Application
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
        'name' => 'bitbucket-pr-webhook-app',
        'git_repository' => 'https://bitbucket.org/test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function sendBitbucketPullRequestWebhook(Application $application, string $event, string $destinationBranch)
{
    $body = json_encode([
        'repository' => [
            'full_name' => 'test-org/test-repo',
            'uuid' => '{repository-uuid}',
        ],
        'pullrequest' => [
            'id' => 42,
            'title' => 'Stacked change',
            'links' => ['html' => ['href' => 'https://bitbucket.org/test-org/test-repo/pull-requests/42']],
            'source' => [
                'branch' => ['name' => 'feature/child'],
                'commit' => ['hash' => 'abc123'],
                'repository' => ['uuid' => '{repository-uuid}'],
            ],
            'destination' => [
                'branch' => ['name' => $destinationBranch],
                'repository' => ['uuid' => '{repository-uuid}'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], [
        'HTTP_X-Event-Key' => $event,
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_bitbucket),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('cleans up the preview of a closed pull request after the destination branch changes', function (string $event) {
    $application = createBitbucketPullRequestWebhookApplication();
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://bitbucket.org/test-org/test-repo/pull-requests/42',
        'git_type' => 'bitbucket',
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

    $response = sendBitbucketPullRequestWebhook($application, $event, 'feature/parent');

    $response->assertOk();
    expect($response->getContent())->toContain('Preview deployment closed.');
})->with(['pullrequest:fulfilled', 'pullrequest:rejected']);

it('continues to filter opened pull requests by destination branch', function () {
    Queue::fake();
    $application = createBitbucketPullRequestWebhookApplication();
    $application->settings->update(['is_preview_deployments_enabled' => true]);

    $response = sendBitbucketPullRequestWebhook($application, 'pullrequest:created', 'feature/parent');

    $response->assertOk();
    expect(ApplicationPreview::where('application_id', $application->id)->exists())->toBeFalse();
});
