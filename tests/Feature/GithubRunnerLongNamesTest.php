<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();
    Queue::fake();

    $team = Team::factory()->create();
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $team->id]);
    $this->longNamesApp = GithubApp::create([
        'name' => 'runner-app',
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 2222,
        'installation_id' => 2223,
        'webhook_secret' => 'runner-secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $this->longNamesApp->id,
        'labels' => ['coolify'],
        'max_runners' => 2,
        'docker_mode' => 'dind',
        'allow_pull_requests' => true,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function sendLongNamesWorkflowJobWebhook($test, GithubApp $githubApp, string $action, array $job)
{
    $body = json_encode([
        'action' => $action,
        'workflow_job' => [
            'id' => 5001,
            'labels' => ['self-hosted', 'coolify'],
            'html_url' => 'https://github.com/acme/api/actions/runs/1/job/5001',
            'workflow_name' => str_repeat('w', 300),
            'name' => str_repeat('ö', 300),
            'runner_name' => null,
            ...$job,
        ],
        'repository' => ['id' => 99, 'full_name' => 'acme/'.str_repeat('r', 300)],
        'organization' => ['login' => 'acme'],
        'installation' => ['id' => $githubApp->installation_id],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'workflow_job',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => (string) $githubApp->app_id,
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'runner-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * SQLite does not enforce varchar(255), so the tests assert the stored lengths that Postgres would reject.
 */
it('stores over-long workflow, job, and repository names of a queued job truncated to the column length', function () {
    sendLongNamesWorkflowJobWebhook($this, $this->longNamesApp, 'queued', [
        'html_url' => 'https://github.com/acme/api/actions/runs/1/job/'.str_repeat('1', 300),
    ])->assertOk()->assertSee('Runner queued.');

    $execution = GithubRunnerExecution::query()->sole();

    expect(mb_strlen($execution->workflow_name))->toBe(255)
        ->and($execution->workflow_name)->toBe(str_repeat('w', 255))
        ->and($execution->job_name)->toBe(str_repeat('ö', 255))
        ->and(mb_strlen($execution->repository_full_name))->toBe(255)
        ->and($execution->workflow_job_html_url)->toBeNull();
    Queue::assertPushed(ProvisionGithubRunnerJob::class);
});

it('stores over-long names and conclusion of a completed job truncated to the column length', function () {
    $execution = GithubRunnerExecution::create([
        'github_app_id' => $this->longNamesApp->id,
        'trigger_workflow_job_id' => 5001,
        'labels' => ['self-hosted', 'coolify'],
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-runner-long',
        'queued_at' => now(),
    ]);

    sendLongNamesWorkflowJobWebhook($this, $this->longNamesApp, 'completed', [
        'runner_name' => 'coolify-runner-long',
        'conclusion' => str_repeat('c', 300),
    ])->assertOk();

    $execution->refresh();
    expect(mb_strlen($execution->workflow_name))->toBe(255)
        ->and(mb_strlen($execution->job_name))->toBe(255)
        ->and(mb_strlen($execution->repository_full_name))->toBe(255)
        ->and(mb_strlen($execution->conclusion))->toBe(255);
});
