<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\ReconcileGithubRunnersJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $this->team = Team::factory()->create();
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $this->team->id]);
    $this->githubApp = GithubApp::create([
        'name' => 'reconcile-disabled-app',
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 2222,
        'installation_id' => 2223,
        'webhook_secret' => 'secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'runner_group_id' => 7,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function reconcileDisabledTestServer(Team $team, GithubApp $githubApp, bool $enabled): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => 'build',
        'is_build_server' => true,
        'force_disabled' => false,
    ]);
    GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['coolify'],
        'max_runners' => 2,
        'docker_mode' => 'dind',
        'is_enabled' => $enabled,
    ]);

    return $server->fresh();
}

function reconcileDisabledTestCommandFor(Server $server): Closure
{
    return fn (PendingProcess $process) => str_contains($process->command, $server->ip);
}

it('reconciles a server with enabled runners', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: true);

    (new ReconcileGithubRunnersJob)->handle();

    Process::assertRan(reconcileDisabledTestCommandFor($server));
});

it('does not connect to a server whose runners are disabled and have no active runners left', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: false);
    GithubRunnerExecution::create([
        'github_app_id' => $this->githubApp->id,
        'server_id' => $server->id,
        'trigger_workflow_job_id' => 3001,
        'labels' => ['self-hosted', 'coolify'],
        'status' => GithubRunnerStatus::Completed,
    ]);

    (new ReconcileGithubRunnersJob)->handle();

    Process::assertDidntRun(reconcileDisabledTestCommandFor($server));
});

it('keeps reconciling a disabled server until its running jobs finish', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: false);
    $running = GithubRunnerExecution::create([
        'github_app_id' => $this->githubApp->id,
        'server_id' => $server->id,
        'github_runner_config_id' => $server->githubRunnerConfig->id,
        'trigger_workflow_job_id' => 3002,
        'labels' => ['self-hosted', 'coolify'],
        'status' => GithubRunnerStatus::Running,
        'started_at' => now(),
    ]);

    (new ReconcileGithubRunnersJob)->handle();

    Process::assertRan(reconcileDisabledTestCommandFor($server));
    expect($running->fresh()->status)->toBe(GithubRunnerStatus::Failed);
});

function reconcileDisabledTestExecution(GithubApp $githubApp, Server $server, GithubRunnerStatus $status): GithubRunnerExecution
{
    static $jobId = 4000;

    return GithubRunnerExecution::create([
        'github_app_id' => $githubApp->id,
        'server_id' => $server->id,
        'trigger_workflow_job_id' => ++$jobId,
        'labels' => ['self-hosted', 'coolify'],
        'status' => $status,
    ]);
}

function reconcileDisabledTestSelectedIds(): array
{
    return (new ReconcileGithubRunnersJob)->serversToReconcile()->pluck('id')->all();
}

it('selects servers with enabled runners or occupying executions', function () {
    $enabled = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: true);
    $disabled = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: false);
    $disabledWithFinished = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: false);
    $executionOnly = Server::factory()->create(['team_id' => $this->team->id]);
    $finishedOnly = Server::factory()->create(['team_id' => $this->team->id]);

    foreach ([GithubRunnerStatus::Queued, GithubRunnerStatus::Completed, GithubRunnerStatus::Failed, GithubRunnerStatus::Cancelled, GithubRunnerStatus::TimedOut] as $status) {
        reconcileDisabledTestExecution($this->githubApp, $disabledWithFinished, $status);
        reconcileDisabledTestExecution($this->githubApp, $finishedOnly, $status);
    }
    reconcileDisabledTestExecution($this->githubApp, $executionOnly, GithubRunnerStatus::Provisioning);

    expect(reconcileDisabledTestSelectedIds())->toEqualCanonicalizing([$enabled->id, $executionOnly->id])
        ->not->toContain($disabled->id);
});

it('selects each server once when several conditions match', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: true);
    foreach (GithubRunnerStatus::occupying() as $status) {
        reconcileDisabledTestExecution($this->githubApp, $server, $status);
    }

    expect(reconcileDisabledTestSelectedIds())->toBe([$server->id]);
});

it('does not select soft-deleted servers', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: true);
    reconcileDisabledTestExecution($this->githubApp, $server, GithubRunnerStatus::Running);
    Server::query()->whereKey($server->id)->update(['deleted_at' => now()]);

    expect(reconcileDisabledTestSelectedIds())->toBe([]);
});

it('follows configuration and execution status changes', function () {
    $server = reconcileDisabledTestServer($this->team, $this->githubApp, enabled: false);
    expect(reconcileDisabledTestSelectedIds())->toBe([]);

    $server->githubRunnerConfig->update(['is_enabled' => true]);
    expect(reconcileDisabledTestSelectedIds())->toBe([$server->id]);

    $server->githubRunnerConfig->update(['is_enabled' => false]);
    $execution = reconcileDisabledTestExecution($this->githubApp, $server, GithubRunnerStatus::Idle);
    expect(reconcileDisabledTestSelectedIds())->toBe([$server->id]);

    $execution->update(['status' => GithubRunnerStatus::Completed]);
    expect(reconcileDisabledTestSelectedIds())->toBe([]);
});
