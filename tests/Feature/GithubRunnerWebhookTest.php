<?php

use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns pong for ping events on the webhook endpoint', function () {
    $response = $this->postJson('/webhooks/source/github/events', [], [
        'X-GitHub-Event' => 'ping',
    ]);

    $response->assertOk();
    $response->assertSee('pong');
});

it('returns nothing to do when no github app found for workflow_job event', function () {
    $payload = [
        'action' => 'queued',
        'workflow_job' => [
            'id' => 12345,
            'labels' => ['self-hosted'],
        ],
        'organization' => [
            'login' => 'test-org',
        ],
    ];

    $secret = 'test-secret';
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, $secret);

    $response = $this->postJson('/webhooks/source/github/events', $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-GitHub-Hook-Installation-Target-Id' => '999999',
        'X-Hub-Signature-256' => 'sha256='.$signature,
    ]);

    $response->assertOk();
    $response->assertSee('Nothing to do. No GitHub App found.');
});

it('dispatches provisioning job for queued workflow_job event', function () {
    $team = \App\Models\Team::factory()->create();
    $privateKeyId = \Illuminate\Support\Facades\DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $githubApp = GithubApp::create([
        'name' => 'test-app',
        'app_id' => 123456,
        'installation_id' => 789,
        'client_id' => 'Iv1.abc123',
        'client_secret' => 'secret',
        'webhook_secret' => 'test-webhook-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $payload = [
        'action' => 'queued',
        'workflow_job' => [
            'id' => 12345,
            'labels' => ['self-hosted', 'coolify'],
            'workflow_name' => 'CI',
        ],
        'organization' => [
            'login' => 'test-org',
        ],
        'repository' => [
            'id' => 123456789,
            'full_name' => 'test-org/repo-one',
        ],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

    $response = $this->postJson('/webhooks/source/github/events', $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-GitHub-Hook-Installation-Target-Id' => '123456',
        'X-Hub-Signature-256' => 'sha256='.$signature,
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk();
    $response->assertSee('Runner provisioning queued.');

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProvisionGithubRunnerJob::class, function ($job) {
        return $job->repositoryId === 123456789
            && $job->repositoryFullName === 'test-org/repo-one';
    });
});

it('dispatches cleanup job for completed workflow_job event', function () {
    $team = \App\Models\Team::factory()->create();
    $privateKeyId = \Illuminate\Support\Facades\DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $githubApp = GithubApp::create([
        'name' => 'test-app',
        'app_id' => 654321,
        'installation_id' => 789,
        'client_id' => 'Iv1.abc123',
        'client_secret' => 'secret',
        'webhook_secret' => 'cleanup-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);

    \Illuminate\Support\Facades\Queue::fake();

    $payload = [
        'action' => 'completed',
        'workflow_job' => [
            'id' => 67890,
            'conclusion' => 'success',
        ],
        'organization' => [
            'login' => 'test-org',
        ],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'cleanup-secret');

    $response = $this->postJson('/webhooks/source/github/events', $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-GitHub-Hook-Installation-Target-Id' => '654321',
        'X-Hub-Signature-256' => 'sha256='.$signature,
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk();
    $response->assertSee('Runner cleanup queued.');

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CleanupGithubRunnerJob::class);
});

it('marks execution as running for in_progress workflow_job event', function () {
    $team = \App\Models\Team::factory()->create();
    $privateKeyId = \Illuminate\Support\Facades\DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $server = Server::factory()->create([
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
    ]);

    $githubApp = GithubApp::create([
        'name' => 'test-app',
        'app_id' => 112233,
        'installation_id' => 789,
        'client_id' => 'Iv1.abc123',
        'client_secret' => 'secret',
        'webhook_secret' => 'inprogress-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);

    $config = GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['self-hosted', 'coolify'],
        'max_runners' => 1,
        'capacity_wait_timeout' => 60,
    ]);

    $execution = GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => \App\Enums\GithubRunnerStatus::Provisioning,
        'runner_name' => 'coolify-preprovision',
        'runner_dir' => '/opt/github-runners/coolify-preprovision',
        'workflow_job_id' => 998877,
    ]);

    $payload = [
        'action' => 'in_progress',
        'workflow_job' => [
            'id' => 998877,
            'runner_name' => 'coolify-live-runner',
        ],
        'organization' => [
            'login' => 'test-org',
        ],
    ];

    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, 'inprogress-secret');

    $response = $this->postJson('/webhooks/source/github/events', $payload, [
        'X-GitHub-Event' => 'workflow_job',
        'X-GitHub-Hook-Installation-Target-Id' => '112233',
        'X-Hub-Signature-256' => 'sha256='.$signature,
        'Content-Type' => 'application/json',
    ]);

    $response->assertOk();
    $response->assertSee('Runner marked running.');

    $execution->refresh();
    expect($execution->status->value)->toBe('running')
        ->and($execution->runner_name)->toBe('coolify-live-runner')
        ->and($execution->started_at)->not->toBeNull();
});
