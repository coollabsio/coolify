<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const FORK_TRUST_SHA = 'e83c5163316f89bfbde7d9ab23ca2e25604af290';

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
});

/**
 * An application with preview deployments on, tracking `main` of acme/app (GitLab project 55).
 * The `gitlab-app` delivery goes to a GitLab App source; the others are manual webhooks.
 */
function forkTrustApplication(string $delivery): Application
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $source = [];
    if ($delivery === 'gitlab-app') {
        $gitlabApp = GitlabApp::create([
            'name' => 'fork-trust-gitlab',
            'api_url' => 'https://gitlab.example.com/api/v4',
            'html_url' => 'https://gitlab.example.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'webhook_token' => 'fork-trust-token',
            'team_id' => $team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
        $source = ['source_id' => $gitlabApp->id, 'source_type' => GitlabApp::class, 'repository_project_id' => 55];
    }

    $application = Application::create([
        'name' => 'fork-trust-app',
        'git_repository' => 'acme/app',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        ...$source,
    ]);
    $application->settings->update(['is_preview_deployments_enabled' => true, 'is_pr_deployments_public_enabled' => false]);

    return $application->refresh();
}

/**
 * Sends a signed "pull request opened" delivery. The head repository is the base repository,
 * or another repository (a fork) when $fromFork is true.
 */
function sendForkTrustPullRequest(TestCase $test, string $delivery, Application $application, bool $fromFork): TestResponse
{
    $server = ['REMOTE_ADDR' => '203.0.113.20', 'CONTENT_TYPE' => 'application/json'];

    if (str_starts_with($delivery, 'gitlab')) {
        $body = json_encode([
            'object_kind' => 'merge_request',
            'project' => ['id' => 55, 'path_with_namespace' => 'acme/app'],
            'object_attributes' => [
                'action' => 'open',
                'iid' => 7,
                'url' => 'https://gitlab.example.com/acme/app/-/merge_requests/7',
                'title' => 'Add feature',
                'source_branch' => 'feature',
                'target_branch' => 'main',
                'source_project_id' => $fromFork ? 999 : 55,
                'target_project_id' => 55,
                'last_commit' => ['id' => FORK_TRUST_SHA, 'message' => 'Add feature'],
            ],
        ], JSON_THROW_ON_ERROR);

        if ($delivery === 'gitlab-app') {
            return $test->call('POST', '/webhooks/source/gitlab/events', [], [], [], $server + [
                'HTTP_X-Gitlab-Event' => 'Merge Request Hook',
                'HTTP_X-Gitlab-Token' => 'fork-trust-token',
            ], $body);
        }

        return $test->call('POST', '/webhooks/source/gitlab/events/manual', [], [], [], $server + [
            'HTTP_X-Gitlab-Token' => $application->manual_webhook_secret_gitlab,
        ], $body);
    }

    if ($delivery === 'bitbucket-manual') {
        $body = json_encode([
            'repository' => ['full_name' => 'acme/app', 'uuid' => '{base}'],
            'pullrequest' => [
                'id' => 7,
                'title' => 'Add feature',
                'links' => ['html' => ['href' => 'https://bitbucket.org/acme/app/pull-requests/7']],
                'source' => ['branch' => ['name' => 'feature'], 'commit' => ['hash' => FORK_TRUST_SHA], 'repository' => ['uuid' => $fromFork ? '{fork}' : '{base}']],
                'destination' => ['branch' => ['name' => 'main'], 'repository' => ['uuid' => '{base}']],
            ],
        ], JSON_THROW_ON_ERROR);

        return $test->call('POST', '/webhooks/source/bitbucket/events/manual', [], [], [], $server + [
            'HTTP_X-Event-Key' => 'pullrequest:created',
            'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_bitbucket),
        ], $body);
    }

    $body = json_encode([
        'action' => 'opened',
        'number' => 7,
        'repository' => ['id' => 55, 'full_name' => 'acme/app'],
        'pull_request' => [
            'html_url' => 'https://gitea.example.com/acme/app/pulls/7',
            'title' => 'Add feature',
            'head' => ['ref' => 'feature', 'sha' => FORK_TRUST_SHA, 'repo' => ['id' => $fromFork ? 999 : 55]],
            'base' => ['ref' => 'main', 'repo' => ['id' => 55]],
        ],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/gitea/events/manual', [], [], [], $server + [
        'HTTP_X-Gitea-Event' => 'pull_request',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $application->manual_webhook_secret_gitea),
    ], $body);
}

function forkTrustPreviewQueued(Application $application): bool
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists();
}

$deliveries = [
    'GitLab App webhook' => ['gitlab-app'],
    'GitLab manual webhook' => ['gitlab-manual'],
    'Gitea manual webhook' => ['gitea-manual'],
    'Bitbucket manual webhook' => ['bitbucket-manual'],
];

it('queues a preview deployment for a pull request from the same repository', function (string $delivery) {
    $application = forkTrustApplication($delivery);

    sendForkTrustPullRequest($this, $delivery, $application, fromFork: false)->assertOk();

    expect(forkTrustPreviewQueued($application))->toBeTrue()
        ->and(ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists())->toBeTrue();
})->with($deliveries);

it('queues no preview deployment for a pull request from a fork without public previews', function (string $delivery) {
    $application = forkTrustApplication($delivery);

    sendForkTrustPullRequest($this, $delivery, $application, fromFork: true)->assertOk();

    expect(forkTrustPreviewQueued($application))->toBeFalse()
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse()
        ->and(ApplicationPreview::query()->where('application_id', $application->id)->exists())->toBeFalse();
})->with($deliveries);
