<?php

use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createGithubPullRequestWebhookApplication(array $overrides = []): Application
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
        'name' => 'github-pr-webhook-app',
        'git_repository' => 'https://github.com/test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $overrides));
}

function githubPullRequestPayload(string $action, string $baseBranch): array
{
    return [
        'action' => $action,
        'number' => 42,
        'repository' => [
            'id' => 987654321,
            'full_name' => 'test-org/test-repo',
        ],
        'pull_request' => [
            'html_url' => 'https://github.com/test-org/test-repo/pull/42',
            'title' => 'Stacked change',
            'author_association' => 'OWNER',
            'head' => [
                'ref' => 'feature/child',
                'sha' => 'head-sha',
            ],
            'base' => [
                'ref' => $baseBranch,
            ],
        ],
    ];
}

it('routes a closed GitHub App pull request to its application after the base branch changes', function () {
    Queue::fake();

    $team = Team::factory()->create();
    $githubApp = GithubApp::create([
        'name' => 'github-app-webhook-test',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 1234567890,
        'webhook_secret' => 'test-secret',
        'is_public' => false,
        'team_id' => $team->id,
    ]);
    $application = createGithubPullRequestWebhookApplication([
        'repository_project_id' => 987654321,
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    $body = json_encode(githubPullRequestPayload('closed', 'feature/parent'), JSON_THROW_ON_ERROR);
    $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'pull_request',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();
    expect($response->getContent())->toContain('PR webhook received');
    Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->applicationId === $application->id
        && $job->action === 'closed'
        && $job->pullRequestId === 42);
});

it('routes a closed manual pull request to its application after the base branch changes', function () {
    Queue::fake();

    $application = createGithubPullRequestWebhookApplication();
    $payload = githubPullRequestPayload('closed', 'feature/parent');
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
        'HTTP_X-GitHub-Event' => 'pull_request',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_github),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertOk();
    Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->applicationId === $application->id
        && $job->action === 'closed'
        && $job->pullRequestId === 42);
});

it('continues to filter non-closed pull requests by base branch', function (string $endpoint) {
    Queue::fake();

    if ($endpoint === 'app') {
        $team = Team::factory()->create();
        $githubApp = GithubApp::create([
            'name' => 'github-app-webhook-test',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 1234567890,
            'webhook_secret' => 'test-secret',
            'is_public' => false,
            'team_id' => $team->id,
        ]);
        createGithubPullRequestWebhookApplication([
            'repository_project_id' => 987654321,
            'source_id' => $githubApp->id,
            'source_type' => GithubApp::class,
        ]);

        $body = json_encode(githubPullRequestPayload('opened', 'feature/parent'), JSON_THROW_ON_ERROR);
        $response = $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'HTTP_X-GitHub-Event' => 'pull_request',
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234567890',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    } else {
        $application = createGithubPullRequestWebhookApplication();
        $body = json_encode(githubPullRequestPayload('opened', 'feature/parent'), JSON_THROW_ON_ERROR);
        $response = $this->call('POST', '/webhooks/source/github/events/manual', [], [], [], [
            'HTTP_X-GitHub-Event' => 'pull_request',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_github),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    $response->assertOk();
    Queue::assertNotPushed(ProcessGithubPullRequestWebhook::class);
})->with(['app', 'manual']);
