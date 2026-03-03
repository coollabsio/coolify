<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeRunnerSetup(array $configOverrides = []): array
{
    $team = Team::factory()->create();
    $privateKeyId = \Illuminate\Support\Facades\DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $server = Server::factory()->create(['private_key_id' => $privateKeyId, 'team_id' => $team->id]);

    // Create functional server settings so isFunctional() returns true
    ServerSetting::updateOrCreate(
        ['server_id' => $server->id],
        ['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false],
    );

    $githubApp = GithubApp::create([
        'name' => 'test-app',
        'app_id' => fake()->unique()->randomNumber(6, true),
        'installation_id' => 789,
        'client_id' => 'Iv1.abc123',
        'client_secret' => 'secret',
        'webhook_secret' => 'test-secret',
        'private_key_id' => $privateKeyId,
        'team_id' => $team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'organization' => 'test-org',
    ]);
    $config = GithubRunnerConfig::create(array_merge([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['self-hosted', 'coolify'],
        'max_runners' => 2,
        'capacity_wait_timeout' => 60,
    ], $configOverrides));

    return compact('team', 'server', 'githubApp', 'config');
}

function makeJob(GithubApp $githubApp, array $overrides = []): ProvisionGithubRunnerJob
{
    return new ProvisionGithubRunnerJob(
        githubAppId: $githubApp->id,
        workflowJobPayload: array_merge([
            'id' => fake()->unique()->randomNumber(8, true),
            'labels' => ['self-hosted', 'coolify'],
            'workflow_name' => 'CI',
        ], $overrides['payload'] ?? []),
        organizationLogin: 'test-org',
        repositoryId: 0,
        capacityWaitStartedAt: $overrides['capacityWaitStartedAt'] ?? null,
    );
}

it('does not create an execution when no config matches the requested labels', function () {
    Queue::fake();
    ['githubApp' => $githubApp] = makeRunnerSetup(['labels' => ['self-hosted', 'coolify']]);

    $job = makeJob($githubApp, ['payload' => ['id' => 99001, 'labels' => ['self-hosted', 'gpu'], 'workflow_name' => 'CI']]);
    $job->handle();

    expect(GithubRunnerExecution::where('workflow_job_id', 99001)->exists())->toBeFalse();
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

it('re-dispatches with a delay when all matching configs are at capacity', function () {
    Queue::fake();
    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup(['max_runners' => 1]);

    // Fill the single runner slot
    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 88001,
        'pid' => 12345,
        'started_at' => now()->subMinutes(5),
    ]);

    $job = makeJob($githubApp, ['payload' => ['id' => 88002, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI']]);
    $job->handle();

    expect(GithubRunnerExecution::where('workflow_job_id', 88002)->exists())->toBeFalse();

    Queue::assertPushed(ProvisionGithubRunnerJob::class, function ($pushedJob) {
        return $pushedJob->capacityWaitStartedAt !== null
            && $pushedJob->workflowJobPayload['id'] === 88002;
    });
});

it('preserves the original capacityWaitStartedAt when re-dispatching', function () {
    Queue::fake();
    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup(['max_runners' => 1]);

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 77001,
        'pid' => 12345,
        'started_at' => now()->subMinutes(5),
    ]);

    $originalStart = now()->subMinutes(30)->toIso8601String();
    $job = makeJob($githubApp, [
        'payload' => ['id' => 77002, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI'],
        'capacityWaitStartedAt' => $originalStart,
    ]);
    $job->handle();

    Queue::assertPushed(ProvisionGithubRunnerJob::class, function ($pushedJob) use ($originalStart) {
        return $pushedJob->capacityWaitStartedAt === $originalStart;
    });
});

it('gives up silently when the capacity wait timeout is exceeded', function () {
    Queue::fake();

    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup([
        'max_runners' => 1,
        'capacity_wait_timeout' => 60,
    ]);

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 66001,
        'pid' => 12345,
        'started_at' => now()->subHours(2),
    ]);

    $job = makeJob($githubApp, [
        'payload' => ['id' => 66002, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI'],
        'capacityWaitStartedAt' => now()->subMinutes(61)->toIso8601String(),
    ]);
    $job->handle();

    expect(GithubRunnerExecution::where('workflow_job_id', 66002)->exists())->toBeFalse();
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

it('uses the configured timeout value for the capacity wait', function () {
    Queue::fake();

    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup([
        'max_runners' => 1,
        'capacity_wait_timeout' => 10, // 10-minute custom timeout
    ]);

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 55001,
        'pid' => 12345,
        'started_at' => now()->subMinutes(15),
    ]);

    // Started 11 minutes ago — exceeds 10-minute custom timeout
    $job = makeJob($githubApp, [
        'payload' => ['id' => 55002, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI'],
        'capacityWaitStartedAt' => now()->subMinutes(11)->toIso8601String(),
    ]);
    $job->handle();

    expect(GithubRunnerExecution::where('workflow_job_id', 55002)->exists())->toBeFalse();
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

it('still re-dispatches when wait time is within the custom timeout', function () {
    Queue::fake();

    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup([
        'max_runners' => 1,
        'capacity_wait_timeout' => 10,
    ]);

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 44001,
        'pid' => 12345,
        'started_at' => now()->subMinutes(5),
    ]);

    // Started 5 minutes ago — within 10-minute timeout
    $job = makeJob($githubApp, [
        'payload' => ['id' => 44002, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI'],
        'capacityWaitStartedAt' => now()->subMinutes(5)->toIso8601String(),
    ]);
    $job->handle();

    Queue::assertPushed(ProvisionGithubRunnerJob::class, fn ($j) => $j->workflowJobPayload['id'] === 44002);
});

it('does not re-dispatch when the job has already been provisioned (idempotency)', function () {
    Queue::fake();
    ['githubApp' => $githubApp, 'config' => $config, 'server' => $server] = makeRunnerSetup();

    GithubRunnerExecution::create([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-existing',
        'runner_dir' => '/opt/github-runners/coolify-existing',
        'workflow_job_id' => 33001,
        'pid' => 99,
        'started_at' => now(),
    ]);

    $job = makeJob($githubApp, ['payload' => ['id' => 33001, 'labels' => ['self-hosted', 'coolify'], 'workflow_name' => 'CI']]);
    $job->handle();

    // Should exit early — no new jobs dispatched, existing execution count unchanged
    expect(GithubRunnerExecution::where('workflow_job_id', 33001)->count())->toBe(1);
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});
