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

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0]);
});

function gitlabSystemHookSha(): string
{
    return 'da1560886d4f094c3e6c9ef40349f7d38b5d27d7';
}

function gitlabSystemHookSource(string $token): GitlabApp
{
    return GitlabApp::create([
        'name' => "gitlab-system-hook-{$token}",
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'webhook_token' => $token,
        'team_id' => Team::factory()->create()->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
}

function gitlabSystemHookApplication(GitlabApp $source, int $projectId = 15, string $branch = 'main'): Application
{
    $project = Project::factory()->create(['team_id' => $source->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $source->team_id]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $application = Application::create([
        'name' => "gitlab-system-hook-app-{$source->id}",
        'git_repository' => 'mike/diaspora',
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $source->id,
        'source_type' => GitlabApp::class,
        'repository_project_id' => $projectId,
    ]);
    $application->settings->update([
        'is_auto_deploy_enabled' => true,
        'is_preview_deployments_enabled' => true,
    ]);

    return $application->refresh();
}

/**
 * A push payload in the shape of the GitLab system hook docs example: it has
 * `event_name` and a top-level `project_id`, but no `object_kind` and no `project.id`.
 *
 * @see https://docs.gitlab.com/administration/system_hooks/#push-events
 *
 * @return array<string, mixed>
 */
function gitlabSystemHookDocumentedPush(int $projectId = 15, string $ref = 'refs/heads/main'): array
{
    return [
        'event_name' => 'push',
        'before' => '95790bf891e76fee5e1747ab589903a6a1f80f22',
        'after' => gitlabSystemHookSha(),
        'ref' => $ref,
        'checkout_sha' => gitlabSystemHookSha(),
        'user_id' => 4,
        'project_id' => $projectId,
        'project' => ['name' => 'Diaspora', 'path_with_namespace' => 'mike/diaspora', 'default_branch' => 'main'],
        'commits' => [[
            'id' => gitlabSystemHookSha(),
            'message' => 'Add simple search to projects in public area',
            'added' => ['app.php'],
            'modified' => [],
            'removed' => [],
        ]],
        'total_commits_count' => 1,
    ];
}

/**
 * A push payload as current GitLab sends it to system hooks
 * (Gitlab::DataBuilder::Push, the same data as for project hooks).
 *
 * @return array<string, mixed>
 */
function gitlabSystemHookRuntimePush(int $projectId = 15): array
{
    $payload = gitlabSystemHookDocumentedPush($projectId);
    $payload['object_kind'] = 'push';
    $payload['project']['id'] = $projectId;

    return $payload;
}

/**
 * @return array<string, mixed>
 */
function gitlabSystemHookMergeRequest(int $projectId = 15, string $action = 'open'): array
{
    return [
        'object_kind' => 'merge_request',
        'event_type' => 'merge_request',
        'project' => ['id' => $projectId, 'path_with_namespace' => 'mike/diaspora'],
        'object_attributes' => [
            'action' => $action,
            'iid' => 1,
            'url' => 'https://gitlab.example.com/mike/diaspora/-/merge_requests/1',
            'title' => 'MS-Viewport',
            'source_branch' => 'ms-viewport',
            'target_branch' => 'main',
            'source_project_id' => $projectId,
            'target_project_id' => $projectId,
            'last_commit' => ['id' => gitlabSystemHookSha(), 'message' => 'Fix viewport'],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function gitlabSystemHookSend(TestCase $test, array $payload, ?string $token, string $event = 'System Hook'): TestResponse
{
    $headers = ['HTTP_X-Gitlab-Event' => $event];
    if ($token !== null) {
        $headers['HTTP_X-Gitlab-Token'] = $token;
    }

    return $test->call('POST', '/webhooks/source/gitlab/events', [], [], [], $headers + [
        'REMOTE_ADDR' => '203.0.113.20',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));
}

function gitlabSystemHookDeployments(Application $application): array
{
    return ApplicationDeploymentQueue::query()->where('application_id', $application->id)->pluck('commit')->all();
}

describe('GitLab system hook push', function () {
    test('a push to the tracked branch queues a deployment', function (array $payload) {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source);

        gitlabSystemHookSend($this, $payload, 'system-hook-token')
            ->assertOk()
            ->assertJsonPath('0.application', $application->name);

        expect(gitlabSystemHookDeployments($application))->toBe([gitlabSystemHookSha()]);
    })->with([
        'documented payload' => [fn () => gitlabSystemHookDocumentedPush()],
        'runtime payload' => [fn () => gitlabSystemHookRuntimePush()],
    ]);

    test('a push to another branch queues no deployment', function () {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source);

        gitlabSystemHookSend($this, gitlabSystemHookDocumentedPush(ref: 'refs/heads/develop'), 'system-hook-token')->assertOk();

        expect(gitlabSystemHookDeployments($application))->toBe([]);
    });

    test('a push for a project that no application tracks queues no deployment', function () {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source, projectId: 15);

        gitlabSystemHookSend($this, gitlabSystemHookDocumentedPush(projectId: 99), 'system-hook-token')
            ->assertOk()
            ->assertExactJson([]);

        expect(gitlabSystemHookDeployments($application))->toBe([]);
    });

    test('a token only deploys applications of its own source', function () {
        $source = gitlabSystemHookSource('system-hook-token');
        $otherSource = gitlabSystemHookSource('other-source-token');
        $application = gitlabSystemHookApplication($source);
        $otherApplication = gitlabSystemHookApplication($otherSource);

        gitlabSystemHookSend($this, gitlabSystemHookDocumentedPush(), 'system-hook-token')->assertOk();

        expect(gitlabSystemHookDeployments($application))->toBe([gitlabSystemHookSha()])
            ->and(gitlabSystemHookDeployments($otherApplication))->toBe([]);
    });

    test('a missing or invalid token is rejected', function (?string $token) {
        $application = gitlabSystemHookApplication(gitlabSystemHookSource('system-hook-token'));

        gitlabSystemHookSend($this, gitlabSystemHookDocumentedPush(), $token)->assertUnauthorized();

        expect(gitlabSystemHookDeployments($application))->toBe([]);
    })->with([
        'missing' => [null],
        'invalid' => ['wrong-token'],
    ]);
});

describe('GitLab system hook merge request', function () {
    test('an opened merge request queues a preview deployment', function () {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source);

        gitlabSystemHookSend($this, gitlabSystemHookMergeRequest(), 'system-hook-token')->assertOk();

        expect(ApplicationPreview::query()->where('application_id', $application->id)->pluck('pull_request_id')->all())->toBe([1])
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->sole()->pull_request_id)->toBe(1);
    });
});

describe('GitLab system hook events without a deployment', function () {
    test('the event is ignored without an error', function (array $payload) {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source);

        gitlabSystemHookSend($this, $payload, 'system-hook-token')
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'Event not allowed. Only push and merge_request events are allowed.');

        expect(gitlabSystemHookDeployments($application))->toBe([]);
    })->with([
        'project_create' => [['event_name' => 'project_create', 'project_id' => 15, 'name' => 'Diaspora', 'path_with_namespace' => 'mike/diaspora']],
        'user_create' => [['event_name' => 'user_create', 'user_id' => 41, 'username' => 'js', 'email' => 'js@gitlabhq.com']],
        'repository_update' => [['event_name' => 'repository_update', 'project_id' => 15, 'changes' => [['ref' => 'refs/heads/main', 'after' => gitlabSystemHookSha()]], 'refs' => ['refs/heads/main']]],
        'tag_push' => [['event_name' => 'tag_push', 'ref' => 'refs/tags/v1.0.0', 'after' => gitlabSystemHookSha(), 'project_id' => 15, 'commits' => []]],
    ]);
});

describe('GitLab project webhook', function () {
    test('a push to the tracked branch still queues a deployment', function () {
        $source = gitlabSystemHookSource('system-hook-token');
        $application = gitlabSystemHookApplication($source);
        $payload = gitlabSystemHookRuntimePush();
        unset($payload['event_name'], $payload['project_id']);

        gitlabSystemHookSend($this, $payload, 'system-hook-token', event: 'Push Hook')->assertOk();

        expect(gitlabSystemHookDeployments($application))->toBe([gitlabSystemHookSha()]);
    });

    test('a payload without object_kind is still rejected', function () {
        $application = gitlabSystemHookApplication(gitlabSystemHookSource('system-hook-token'));

        gitlabSystemHookSend($this, gitlabSystemHookDocumentedPush(), 'system-hook-token', event: 'Push Hook')
            ->assertOk()
            ->assertJsonPath('message', 'Event not allowed. Only push and merge_request events are allowed.');

        expect(gitlabSystemHookDeployments($application))->toBe([]);
    });
});
