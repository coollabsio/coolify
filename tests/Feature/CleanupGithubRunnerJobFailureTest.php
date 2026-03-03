<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks cleaning execution as failed when cleanup job fails', function () {
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
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

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
        'status' => GithubRunnerStatus::Cleaning,
        'runner_name' => 'coolify-cleaning',
        'runner_dir' => '/opt/github-runners/coolify-cleaning',
        'workflow_job_id' => 123456,
        'started_at' => now()->subMinute(),
    ]);

    $job = new CleanupGithubRunnerJob(workflowJobId: 123456);
    $job->failed(new RuntimeException('simulated cleanup crash'));

    $execution->refresh();

    expect($execution->status)->toBe(GithubRunnerStatus::Failed)
        ->and($execution->error_message)->toContain('simulated cleanup crash')
        ->and($execution->completed_at)->not->toBeNull()
        ->and($config->fresh()->activeRunnerCount())->toBe(0)
        ->and($config->fresh()->hasCapacity())->toBeTrue();
});

it('marks running execution as failed when cleanup job fails before cleaning transition', function () {
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
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);

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
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-running',
        'runner_dir' => '/opt/github-runners/coolify-running',
        'workflow_job_id' => 123457,
        'started_at' => now()->subMinute(),
    ]);

    $job = new CleanupGithubRunnerJob(workflowJobId: 123457);
    $job->failed(new RuntimeException('simulated early cleanup crash'));

    $execution->refresh();

    expect($execution->status)->toBe(GithubRunnerStatus::Failed)
        ->and($execution->error_message)->toContain('simulated early cleanup crash')
        ->and($execution->completed_at)->not->toBeNull()
        ->and($config->fresh()->activeRunnerCount())->toBe(0)
        ->and($config->fresh()->hasCapacity())->toBeTrue();
});

it('marks execution as failed when cleanup runs on a non-functional server', function () {
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
    $server->settings()->update(['is_reachable' => false, 'is_usable' => false, 'force_disabled' => false]);

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
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-running',
        'runner_dir' => '/opt/github-runners/coolify-running',
        'workflow_job_id' => 999001,
        'started_at' => now()->subMinute(),
    ]);

    $job = new CleanupGithubRunnerJob(workflowJobId: 999001);
    $job->handle();

    $execution->refresh();

    expect($execution->status)->toBe(GithubRunnerStatus::Failed)
        ->and($execution->error_message)->toBe('Cleanup skipped: server is not functional.')
        ->and($execution->completed_at)->not->toBeNull()
        ->and($config->fresh()->activeRunnerCount())->toBe(0)
        ->and($config->fresh()->hasCapacity())->toBeTrue();
});
