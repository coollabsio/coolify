<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\GiteaApp;
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

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->giteaApp = giteaAppWebhookSource($this->team, 'gitea-app-secret');
});

function giteaAppWebhookSource(Team $team, string $secret): GiteaApp
{
    return GiteaApp::create([
        'name' => 'gitea-app-'.str()->random(6),
        'api_url' => 'https://gitea.example.com/api/v1',
        'html_url' => 'https://gitea.example.com',
        'webhook_secret' => $secret,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
}

function giteaAppWebhookApplication(Team $team, int $sourceId, string $sourceType = GiteaApp::class): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $application = Application::create([
        'name' => 'gitea-app-webhook-app',
        'git_repository' => 'test-org/test-repo',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $sourceId,
        'source_type' => $sourceType,
        'repository_project_id' => 555,
    ]);
    $application->settings->update(['is_auto_deploy_enabled' => true, 'is_preview_deployments_enabled' => true]);

    return $application->refresh();
}

/**
 * @return array<string, mixed>
 */
function giteaAppWebhookPayload(string $event, string $action = 'opened'): array
{
    $sha = 'e83c5163316f89bfbde7d9ab23ca2e25604af290';

    if ($event === 'push') {
        return [
            'ref' => 'refs/heads/main',
            'before' => 'a83c5163316f89bfbde7d9ab23ca2e25604af290',
            'after' => $sha,
            'repository' => ['id' => 555, 'full_name' => 'test-org/test-repo'],
            'commits' => [['message' => 'Update', 'added' => [], 'modified' => ['README.md'], 'removed' => []]],
        ];
    }

    return [
        'action' => $action,
        'number' => 7,
        'repository' => ['id' => 555, 'full_name' => 'test-org/test-repo'],
        'pull_request' => [
            'html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/7',
            'title' => 'Add feature',
            'head' => ['ref' => 'feature', 'sha' => $sha, 'repo' => ['id' => 555]],
            'base' => ['ref' => 'main', 'repo' => ['id' => 555]],
        ],
    ];
}

/**
 * @param  array<string, string>  $headers
 */
function sendGiteaAppWebhook(TestCase $test, string $event, array $payload, ?string $secret = 'gitea-app-secret', array $headers = []): TestResponse
{
    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X-Gitea-Event' => $event];
    if ($secret !== null) {
        $server['HTTP_X-Gitea-Signature'] = hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', '/webhooks/source/gitea/events', [], [], [], array_merge($server, $headers), $body);
}

describe('Gitea App webhook authentication', function () {
    test('rejects a delivery without a signature', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);

        sendGiteaAppWebhook($this, 'push', giteaAppWebhookPayload('push'), secret: null)->assertStatus(401);

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });

    test('rejects a delivery with an invalid signature', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);

        sendGiteaAppWebhook($this, 'push', giteaAppWebhookPayload('push'), secret: 'wrong-secret')->assertStatus(401);

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });

    test('accepts a GitHub-style X-Hub-Signature-256 header', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);
        $payload = giteaAppWebhookPayload('push');
        $body = json_encode($payload);

        $this->call('POST', '/webhooks/source/gitea/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Gitea-Event' => 'push',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gitea-app-secret'),
        ], $body)->assertOk();

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeTrue();
    });

    test('answers ping deliveries', function () {
        sendGiteaAppWebhook($this, 'ping', ['hook_id' => 1])->assertOk()->assertSee('pong');
    });
});

describe('Gitea App webhook push', function () {
    test('queues a deployment only for applications of the signing Gitea App', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);

        $otherGiteaApp = giteaAppWebhookSource(Team::factory()->create(), 'other-secret');
        $otherGiteaApplication = giteaAppWebhookApplication($otherGiteaApp->team, $otherGiteaApp->id);

        // A GitLab App with the same id must not have its applications deployed.
        $gitlabApp = GitlabApp::forceCreate([
            'id' => $this->giteaApp->id,
            'uuid' => (string) str()->uuid(),
            'name' => 'colliding-gitlab-app',
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
        $gitlabApplication = giteaAppWebhookApplication($this->team, $gitlabApp->id, GitlabApp::class);

        sendGiteaAppWebhook($this, 'push', giteaAppWebhookPayload('push'))
            ->assertOk()
            ->assertSee('gitea-app-webhook-app');

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->where('commit', 'e83c5163316f89bfbde7d9ab23ca2e25604af290')->exists())->toBeTrue()
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $otherGiteaApplication->id)->exists())->toBeFalse()
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $gitlabApplication->id)->exists())->toBeFalse();
    });

    test('does not deploy a push to another branch', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);
        $payload = giteaAppWebhookPayload('push');
        $payload['ref'] = 'refs/heads/develop';

        sendGiteaAppWebhook($this, 'push', $payload)->assertOk();

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });

    test('skips commits marked with [skip ci]', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);
        $payload = giteaAppWebhookPayload('push');
        $payload['commits'][0]['message'] = 'Docs [skip ci]';

        sendGiteaAppWebhook($this, 'push', $payload)->assertOk()->assertSee('skipped');

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });
});

describe('Gitea App webhook pull requests', function () {
    test('an opened pull request creates a preview and queues a preview deployment', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);

        sendGiteaAppWebhook($this, 'pull_request', giteaAppWebhookPayload('pull_request', 'opened'))->assertOk();

        $preview = ApplicationPreview::query()->where('application_id', $application->id)->where('pull_request_id', 7)->first();
        expect($preview)->not->toBeNull()
            ->and($preview->git_type)->toBe('gitea')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->where('pull_request_id', 7)->exists())->toBeTrue();
    });

    test('a pull request from a fork is not deployed without public previews', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);
        $payload = giteaAppWebhookPayload('pull_request', 'opened');
        $payload['pull_request']['head']['repo']['id'] = 999;

        sendGiteaAppWebhook($this, 'pull_request', $payload)->assertOk();

        expect(ApplicationPreview::query()->where('application_id', $application->id)->exists())->toBeFalse()
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->exists())->toBeFalse();
    });

    test('a closed pull request cleans up the preview deployment', function () {
        $application = giteaAppWebhookApplication($this->team, $this->giteaApp->id);
        ApplicationPreview::create([
            'git_type' => 'gitea',
            'application_id' => $application->id,
            'pull_request_id' => 7,
            'pull_request_html_url' => 'https://gitea.example.com/test-org/test-repo/pulls/7',
        ]);
        CleanupPreviewDeployment::shouldRun()->once();

        sendGiteaAppWebhook($this, 'pull_request', giteaAppWebhookPayload('pull_request', 'closed'))
            ->assertOk()
            ->assertSee('Preview deployment closed');
    });
});
