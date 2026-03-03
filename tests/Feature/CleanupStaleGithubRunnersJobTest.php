<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupStaleGithubRunnersJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeExecution(array $attributes = []): GithubRunnerExecution
{
    $team = Team::factory()->create();
    $privateKeyId = DB::table('private_keys')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'test-key',
        'private_key' => encrypt('test'),
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $server = Server::factory()->create(['private_key_id' => $privateKeyId, 'team_id' => $team->id]);
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
    ]);

    return GithubRunnerExecution::create(array_merge([
        'server_id' => $server->id,
        'github_runner_config_id' => $config->id,
        'status' => GithubRunnerStatus::Running,
        'runner_name' => 'coolify-test',
        'runner_dir' => '/opt/github-runners/coolify-test',
        'workflow_job_id' => fake()->unique()->randomNumber(8, true),
        'pid' => 12345,
        'started_at' => now()->subMinutes(10),
    ], $attributes));
}

it('skips dead-runner check for executions within the 5-minute grace period', function () {
    $execution = makeExecution(['started_at' => now()->subMinutes(2)]);

    (new CleanupStaleGithubRunnersJob)->handle();

    expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Running);
});

it('skips dead-runner check when server is not functional', function () {
    // Factory servers have no settings → isFunctional() returns false
    $execution = makeExecution(['started_at' => now()->subMinutes(10)]);

    (new CleanupStaleGithubRunnersJob)->handle();

    // Should remain Running because the health check is skipped for non-functional servers
    expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Running);
});

it('marks stale active executions as timed out after 2 hours', function () {
    $execution = makeExecution([
        'status' => GithubRunnerStatus::Running,
        'started_at' => now()->subHours(3),
        'created_at' => now()->subHours(3),
    ]);

    // Force the created_at to be old enough
    $execution->forceFill(['created_at' => now()->subHours(3)])->save();

    (new CleanupStaleGithubRunnersJob)->handle();

    $fresh = $execution->fresh();
    expect($fresh->status)->toBe(GithubRunnerStatus::TimedOut);
    expect($fresh->completed_at)->not->toBeNull();
    expect($fresh->error_message)->toContain('2 hours');
});

it('does not touch active executions younger than 2 hours in stale cleanup', function () {
    $execution = makeExecution([
        'status' => GithubRunnerStatus::Provisioning,
        'started_at' => now()->subHour(),
    ]);

    (new CleanupStaleGithubRunnersJob)->handle();

    expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Provisioning);
});

it('marks stale queued executions as timed out', function () {
    $execution = makeExecution([
        'status' => GithubRunnerStatus::Queued,
        'started_at' => null,
        'pid' => null,
    ]);
    $execution->forceFill(['created_at' => now()->subHours(3)])->save();

    (new CleanupStaleGithubRunnersJob)->handle();

    expect($execution->fresh()->status)->toBe(GithubRunnerStatus::TimedOut);
});

it('does not re-process already completed executions', function () {
    $execution = makeExecution([
        'status' => GithubRunnerStatus::Completed,
        'started_at' => now()->subHours(3),
        'completed_at' => now()->subHours(2),
    ]);
    $execution->forceFill(['created_at' => now()->subHours(3)])->save();

    (new CleanupStaleGithubRunnersJob)->handle();

    expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Completed);
});
