<?php

use App\Actions\Server\DeleteServer;
use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\DeregisterGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();
    Sleep::fake();

    $this->team = Team::factory()->create();
    $this->githubApp = runnerTestGithubApp($this->team, 1111, 'runner-secret');
});

afterEach(function () {
    Server::flushIdentityMap();
});

function runnerTestGithubApp(Team $team, int $appId, string $secret): GithubApp
{
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Runner key', 'private_key' => $pemKey, 'team_id' => $team->id]);

    return GithubApp::create([
        'name' => "runner-app-{$appId}",
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => $appId,
        'installation_id' => $appId + 1,
        'webhook_secret' => $secret,
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
        'runner_group_id' => 7,
    ]);
}

function runnerTestServer(Team $team, string $role = 'build'): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'server_role' => $role,
        'is_build_server' => $role === 'build',
        'force_disabled' => false,
    ]);

    return $server->fresh();
}

function runnerTestConfig(Server $server, GithubApp $githubApp, array $attributes = []): GithubRunnerConfig
{
    return GithubRunnerConfig::create([
        'server_id' => $server->id,
        'github_app_id' => $githubApp->id,
        'labels' => ['coolify'],
        'max_runners' => 2,
        'docker_mode' => 'dind',
        ...$attributes,
    ]);
}

function runnerTestExecution(GithubApp $githubApp, array $attributes = []): GithubRunnerExecution
{
    static $jobId = 1000;

    return GithubRunnerExecution::create([
        'github_app_id' => $githubApp->id,
        'trigger_workflow_job_id' => ++$jobId,
        'labels' => ['self-hosted', 'coolify'],
        'status' => GithubRunnerStatus::Queued,
        'queued_at' => now(),
        ...$attributes,
    ]);
}

function sendWorkflowJobWebhook($test, GithubApp $githubApp, string $secret, string $action, array $job, ?int $installationId = null)
{
    $body = json_encode([
        'action' => $action,
        'workflow_job' => [
            'id' => 5001,
            'labels' => ['self-hosted', 'coolify'],
            'html_url' => 'https://github.com/acme/api/actions/runs/1/job/5001',
            'workflow_name' => 'CI',
            'name' => 'test',
            'runner_name' => null,
            ...$job,
        ],
        'repository' => ['id' => 99, 'full_name' => 'acme/api'],
        'organization' => ['login' => 'acme'],
        'installation' => ['id' => $installationId ?? $githubApp->installation_id],
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'workflow_job',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => (string) $githubApp->app_id,
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function fakeRunnerGithubApi(GithubApp $githubApp): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        "https://api.github.com/app/installations/{$githubApp->installation_id}/access_tokens" => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/orgs/acme/actions/runners/generate-jitconfig' => Http::response([
            'runner' => ['id' => 42, 'name' => 'runner'],
            'encoded_jit_config' => 'SECRET-JIT-CONFIG',
        ], 201),
        'https://api.github.com/orgs/acme/actions/runners/*' => Http::response(null, 204),
    ]);
}

function fakeWorkflowRunApi(GithubApp $githubApp, mixed $runResponse): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        "https://api.github.com/app/installations/{$githubApp->installation_id}/access_tokens" => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/repos/acme/api/actions/runs/321' => $runResponse,
    ]);
}

describe('workflow_job webhook', function () {
    beforeEach(function () {
        Queue::fake();
        $this->server = runnerTestServer($this->team);
        runnerTestConfig($this->server, $this->githubApp);
    });

    it('creates one queued execution for duplicate deliveries of a queued job', function () {
        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', [])->assertOk();
        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', [])->assertOk();

        $execution = GithubRunnerExecution::sole();
        expect($execution->status)->toBe(GithubRunnerStatus::Queued)
            ->and($execution->trigger_workflow_job_id)->toBe(5001)
            ->and($execution->repository_full_name)->toBe('acme/api');
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
    });

    it('ignores jobs that do not ask for a custom label', function (array $labels) {
        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', ['labels' => $labels])
            ->assertOk()
            ->assertSee('No runner configuration matches');

        expect(GithubRunnerExecution::count())->toBe(0);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    })->with([
        'generic self-hosted' => [['self-hosted']],
        'unknown label' => [['self-hosted', 'coolify', 'gpu']],
        'github hosted' => [['ubuntu-latest']],
    ]);

    it('does not provision a runner for a pull request job when no matching configuration allows them', function (string $event) {
        Http::preventStrayRequests();
        fakeWorkflowRunApi($this->githubApp, Http::response(['id' => 321, 'event' => $event]));

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', ['run_id' => 321])
            ->assertOk()
            ->assertSee('does not allow pull request jobs');

        expect(GithubRunnerExecution::count())->toBe(0);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    })->with(['pull_request', 'pull_request_target']);

    it('provisions a runner for a job that does not come from a pull request', function (mixed $runResponse) {
        Http::preventStrayRequests();
        fakeWorkflowRunApi($this->githubApp, $runResponse);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', ['run_id' => 321])->assertOk();

        expect(GithubRunnerExecution::sole()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
    })->with([
        'push' => fn () => Http::response(['id' => 321, 'event' => 'push']),
        'workflow run lookup fails' => fn () => Http::response(['message' => 'Server Error'], 500),
    ]);

    it('provisions a runner for a pull request job without a lookup when a matching configuration allows them', function () {
        Http::preventStrayRequests();
        Http::fake();
        GithubRunnerConfig::query()->update(['allow_pull_requests' => true]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', ['run_id' => 321])->assertOk();

        expect(GithubRunnerExecution::count())->toBe(1);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
        Http::assertNothingSent();
    });

    it('ignores jobs from another installation of the GitHub App', function (string $action) {
        $execution = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Running,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-abc',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', $action, [
            'id' => 6001,
            'runner_name' => 'coolify-runner-abc',
            'conclusion' => 'success',
        ], installationId: 987654)
            ->assertOk()
            ->assertSee('another installation');

        expect(GithubRunnerExecution::count())->toBe(1)
            ->and($execution->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertNothingPushed();
    })->with(['queued', 'completed']);

    it('marks a pull request job when only some matching configurations allow pull request jobs', function () {
        Http::preventStrayRequests();
        fakeWorkflowRunApi($this->githubApp, Http::response(['id' => 321, 'event' => 'pull_request']));
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp, ['allow_pull_requests' => true]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'queued', ['run_id' => 321])->assertOk();

        expect(GithubRunnerExecution::sole()->is_pull_request)->toBeTrue();
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
    });

    it('rejects an invalid signature', function () {
        sendWorkflowJobWebhook($this, $this->githubApp, 'wrong-secret', 'queued', [])->assertSee('Invalid signature');

        expect(GithubRunnerExecution::count())->toBe(0);
    });

    it('marks the runner that took the job as running, even when it was started for another job', function () {
        $execution = runnerTestExecution($this->githubApp, [
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-abc',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', [
            'id' => 7777,
            'runner_name' => 'coolify-runner-abc',
        ])->assertOk();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Running)
            ->and($execution->workflow_job_id)->toBe(7777)
            ->and($execution->started_at)->not->toBeNull();
    });

    it('starts a new runner for the job whose runner another job took', function () {
        $stolen = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'workflow_job_id' => 5001,
            'job_name' => 'build',
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
            'provision_attempts' => 1,
        ]);

        foreach ([1, 2] as $delivery) {
            sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', [
                'id' => 6001,
                'name' => 'pr-test',
                'runner_name' => 'coolify-runner-a',
            ])->assertOk();
        }

        $stolen->refresh();
        $replacement = GithubRunnerExecution::query()->where('trigger_workflow_job_id', 5001)->sole();
        expect($stolen->status)->toBe(GithubRunnerStatus::Running)
            ->and($stolen->trigger_workflow_job_id)->toBe(6001)
            ->and($stolen->workflow_job_id)->toBe(6001)
            ->and($replacement->id)->not->toBe($stolen->id)
            ->and($replacement->status)->toBe(GithubRunnerStatus::Queued)
            ->and($replacement->job_name)->toBe('build')
            ->and($replacement->labels)->toBe(['self-hosted', 'coolify'])
            ->and($replacement->provision_attempts)->toBe(1);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $replacement->id);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', [
            'id' => 6001,
            'runner_name' => 'coolify-runner-a',
            'conclusion' => 'success',
        ])->assertOk();

        expect($stolen->fresh()->status)->toBe(GithubRunnerStatus::Completed)
            ->and($replacement->fresh()->status)->toBe(GithubRunnerStatus::Queued);
    });

    it('starts a new runner when the completed hook of the other job arrives before its in_progress hook', function () {
        $stolen = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
            'provision_attempts' => 1,
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', [
            'id' => 6001,
            'runner_name' => 'coolify-runner-a',
            'conclusion' => 'success',
        ])->assertOk();

        expect($stolen->fresh()->status)->toBe(GithubRunnerStatus::Completed)
            ->and($stolen->fresh()->trigger_workflow_job_id)->toBe(6001)
            ->and(GithubRunnerExecution::query()->where('trigger_workflow_job_id', 5001)->sole()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
    });

    it('replaces a stale execution of the job that took the runner', function () {
        $stale = runnerTestExecution($this->githubApp, ['trigger_workflow_job_id' => 6001, 'status' => GithubRunnerStatus::TimedOut]);
        $stolen = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
            'provision_attempts' => 1,
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', [
            'id' => 6001,
            'runner_name' => 'coolify-runner-a',
        ])->assertOk();

        expect($stale->fresh())->toBeNull()
            ->and($stolen->fresh()->trigger_workflow_job_id)->toBe(6001)
            ->and(GithubRunnerExecution::query()->where('trigger_workflow_job_id', 5001)->sole()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
    });

    it('does not start a new runner when the runner took another job with its own pending runner', function () {
        $first = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
        ]);
        $second = runnerTestExecution($this->githubApp, ['trigger_workflow_job_id' => 6001]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', [
            'id' => 6001,
            'runner_name' => 'coolify-runner-a',
        ])->assertOk();

        expect(GithubRunnerExecution::count())->toBe(2)
            ->and($first->fresh()->status)->toBe(GithubRunnerStatus::Running)
            ->and($first->fresh()->workflow_job_id)->toBe(6001)
            ->and($second->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    });

    it('does not start a new runner for a job whose runner was taken', function (Closure $setup, array $attributes) {
        $setup();
        $stolen = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
            'provision_attempts' => 1,
            ...$attributes,
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', [
            'id' => 6001,
            'runner_name' => 'coolify-runner-a',
        ])->assertOk();

        expect(GithubRunnerExecution::count())->toBe(1)
            ->and($stolen->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    })->with([
        'runner limit of the job reached' => [fn () => null, ['provision_attempts' => ProvisionGithubRunnerJob::MAX_ATTEMPTS]],
        'configuration disabled' => [fn () => GithubRunnerConfig::query()->update(['is_enabled' => false]), []],
        'pull request job no longer allowed' => [fn () => null, ['is_pull_request' => true]],
    ]);

    it('marks the runner running without a new runner when it took the job it was started for', function () {
        $execution = runnerTestExecution($this->githubApp, [
            'trigger_workflow_job_id' => 5001,
            'status' => GithubRunnerStatus::Idle,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-a',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'in_progress', ['runner_name' => 'coolify-runner-a'])->assertOk();

        $execution->refresh();
        expect(GithubRunnerExecution::count())->toBe(1)
            ->and($execution->status)->toBe(GithubRunnerStatus::Running)
            ->and($execution->trigger_workflow_job_id)->toBe(5001)
            ->and($execution->workflow_job_id)->toBe(5001);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    });

    it('completes the runner and queues its cleanup', function () {
        $execution = runnerTestExecution($this->githubApp, [
            'status' => GithubRunnerStatus::Running,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-abc',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', [
            'runner_name' => 'coolify-runner-abc',
            'conclusion' => 'success',
        ])->assertOk();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Completed)
            ->and($execution->conclusion)->toBe('success');
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
    });

    it('does not touch runners of another GitHub App with the same runner name', function () {
        $otherTeam = Team::factory()->create();
        $otherApp = runnerTestGithubApp($otherTeam, 2222, 'other-secret');
        $victim = runnerTestExecution($this->githubApp, [
            'status' => GithubRunnerStatus::Running,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-abc',
        ]);

        sendWorkflowJobWebhook($this, $otherApp, 'other-secret', 'completed', [
            'runner_name' => 'coolify-runner-abc',
            'conclusion' => 'cancelled',
        ])->assertOk();

        expect($victim->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertNotPushed(CleanupGithubRunnerJob::class);
    });

    it('cancels the queued execution of a job that ended without a Coolify runner', function () {
        $execution = runnerTestExecution($this->githubApp, ['trigger_workflow_job_id' => 5001]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', ['conclusion' => 'cancelled'])->assertOk();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Cancelled);
    });

    it('keeps the queued execution when its job ran on a Coolify runner started for another job', function () {
        $queued = runnerTestExecution($this->githubApp, ['trigger_workflow_job_id' => 5001]);
        runnerTestExecution($this->githubApp, [
            'status' => GithubRunnerStatus::Running,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-other',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', [
            'runner_name' => 'coolify-runner-other',
            'conclusion' => 'success',
        ])->assertOk();

        expect($queued->fresh()->status)->toBe(GithubRunnerStatus::Queued);
    });
});

describe('provisioning', function () {
    it('starts a runner on the least busy matching build server', function () {
        Queue::fake();
        fakeRunnerGithubApi($this->githubApp);
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        $busyServer = runnerTestServer($this->team);
        $busyConfig = runnerTestConfig($busyServer, $this->githubApp);
        runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $busyServer->id, 'github_runner_config_id' => $busyConfig->id]);
        $freeServer = runnerTestServer($this->team);
        runnerTestConfig($freeServer, $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Idle)
            ->and($execution->server_id)->toBe($freeServer->id)
            ->and($execution->runner_id)->toBe(42)
            ->and($execution->runner_name)->toBe("coolify-runner-{$execution->uuid}")
            ->and($execution->ready_at)->not->toBeNull();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/generate-jitconfig')
            && $request['labels'] === ['self-hosted', 'linux', 'coolify']
            && $request['runner_group_id'] === 7);

        $commands = '';
        Process::assertRan(function (PendingProcess $process) use (&$commands) {
            $commands .= $process->command."\n";

            return true;
        });
        expect($commands)
            ->toContain("docker run -d --privileged --name coolify-runner-{$execution->uuid}-dind")
            ->toContain('--env-file /data/coolify/github-runners/'.$execution->uuid.'.env')
            ->not->toContain('SECRET-JIT-CONFIG')
            ->not->toContain('/var/run/docker.sock:/var/run/docker.sock');
    });

    it('keeps the execution queued when every server is at capacity', function () {
        Queue::fake();
        Http::fake();
        Process::fake();
        $server = runnerTestServer($this->team);
        $config = runnerTestConfig($server, $this->githubApp, ['max_runners' => 1]);
        runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Idle, 'server_id' => $server->id, 'github_runner_config_id' => $config->id]);
        $execution = runnerTestExecution($this->githubApp);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Http::assertNothingSent();
        Process::assertNothingRan();
    });

    it('skips configs whose server is being deleted', function () {
        Queue::fake();
        fakeRunnerGithubApi($this->githubApp);
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        $deletedServer = runnerTestServer($this->team);
        runnerTestConfig($deletedServer, $this->githubApp);
        $deletedServer->delete();
        $server = runnerTestServer($this->team);
        runnerTestConfig($server, $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->server_id)->toBe($server->id);
    });

    it('places a pull request job only on a server whose configuration allows pull request jobs', function () {
        Queue::fake();
        fakeRunnerGithubApi($this->githubApp);
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        $refusingServer = runnerTestServer($this->team);
        runnerTestConfig($refusingServer, $this->githubApp, ['allow_pull_requests' => false]);
        $allowingServer = runnerTestServer($this->team);
        $allowingConfig = runnerTestConfig($allowingServer, $this->githubApp, ['allow_pull_requests' => true]);
        runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $allowingServer->id, 'github_runner_config_id' => $allowingConfig->id]);
        $execution = runnerTestExecution($this->githubApp, ['is_pull_request' => true]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->server_id)->toBe($allowingServer->id);
    });

    it('keeps a pull request job queued when no configuration with capacity allows pull request jobs', function () {
        Queue::fake();
        Process::fake();
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp, ['allow_pull_requests' => false]);
        $execution = runnerTestExecution($this->githubApp, ['is_pull_request' => true]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Process::assertNothingRan();
    });

    it('stops provisioning when the server is deleted while GitHub registers the runner', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        $server = runnerTestServer($this->team);
        runnerTestConfig($server, $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/*' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/orgs/acme/actions/runners/generate-jitconfig' => function () use ($server) {
                DeleteServer::run($server->id);

                return Http::response(['runner' => ['id' => 42], 'encoded_jit_config' => 'SECRET-JIT-CONFIG'], 201);
            },
        ]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect(GithubRunnerExecution::find($execution->id))->toBeNull();
        Process::assertDidntRun(fn (PendingProcess $process) => str_contains($process->command, '/home/runner/run.sh'));
        Queue::assertPushed(DeregisterGithubRunnerJob::class, fn ($job) => $job->githubAppId === $this->githubApp->id && $job->runnerId === 42);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
    });

    it('does not start runners on the Coolify host', function () {
        Queue::fake();
        Process::fake();
        $server = runnerTestServer($this->team);
        $server->update(['ip' => 'host.docker.internal']);
        runnerTestConfig($server, $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Process::assertNothingRan();
    });

    it('does not use servers without the build role, disabled configs, or other labels', function (string $role, array $configAttributes) {
        Queue::fake();
        Process::fake();
        $server = runnerTestServer($this->team, $role);
        runnerTestConfig($server, $this->githubApp, $configAttributes);
        $execution = runnerTestExecution($this->githubApp);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Process::assertNothingRan();
    })->with([
        'combined role' => ['both', []],
        'disabled config' => ['build', ['is_enabled' => false]],
        'other labels' => ['build', ['labels' => ['gpu']]],
    ]);

    it('cleans up a runner that failed to start and retries the job later', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        fakeRejectedJitConfig();
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);
        $uuid = $execution->uuid;

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Queued)
            ->and($execution->provision_attempts)->toBe(1)
            ->and($execution->server_id)->toBeNull()
            ->and($execution->runner_name)->toBeNull()
            ->and($execution->error_message)->toContain('Attempt 1 of 3 failed')->toContain('Resource not accessible by integration');
        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "docker rm -f -v coolify-runner-{$uuid}"));
        Queue::assertPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id && $job->delay !== null);
        Queue::assertNotPushed(CleanupGithubRunnerJob::class);
    });

    it('preserves a terminal execution when provisioning fails', function (GithubRunnerStatus $status, int $attempts) {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp, ['provision_attempts' => $attempts]);
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/*' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/orgs/acme/actions/runners/42' => Http::response(null, 204),
            'https://api.github.com/orgs/acme/actions/runners/generate-jitconfig' => function () use ($execution, $status) {
                $execution->finish($status, 'Stopped externally');

                return Http::response(['message' => 'Resource not accessible by integration'], 403);
            },
        ]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe($status)
            ->and($execution->fresh()->error_message)->toBe('Stopped externally');
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
    })->with([
        'cancelled during first attempt' => [GithubRunnerStatus::Cancelled, 0],
        'cancelled during last attempt' => [GithubRunnerStatus::Cancelled, 2],
        'timed out' => [GithubRunnerStatus::TimedOut, 0],
        'completed' => [GithubRunnerStatus::Completed, 0],
    ]);

    it('does not start a runner cancelled while GitHub registers it', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/*' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/orgs/acme/actions/runners/42' => Http::response(null, 204),
            'https://api.github.com/orgs/acme/actions/runners/generate-jitconfig' => function () use ($execution) {
                $execution->finish(GithubRunnerStatus::Cancelled);

                return Http::response(['runner' => ['id' => 42], 'encoded_jit_config' => 'SECRET-JIT-CONFIG'], 201);
            },
        ]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($execution->fresh()->ready_at)->toBeNull();
        Process::assertDidntRun(fn (PendingProcess $process) => str_contains($process->command, '/home/runner/run.sh'));
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
    });

    it('cleans up cancellation during remote provisioning without marking the runner ready', function (bool $duringStart) {
        Queue::fake();
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);
        fakeRunnerGithubApi($this->githubApp);
        Process::fake(['*' => function (PendingProcess $process) use ($execution, $duringStart) {
            if (! $duringStart || str_contains($process->command, '/home/runner/run.sh')) {
                $execution->finish(GithubRunnerStatus::Cancelled);
            }

            return Process::result(output: '29.8.0');
        }]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($execution->fresh()->ready_at)->toBeNull();
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
        if (! $duringStart) {
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/generate-jitconfig'));
        }
    })->with(['during preparation' => false, 'during start' => true]);

    it('does not retry an execution cancelled while failed resources are removed', function () {
        Queue::fake();
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp);
        fakeRejectedJitConfig();
        Process::fake(['*' => function (PendingProcess $process) use ($execution) {
            if (str_contains($process->command, 'docker rm -f -v')) {
                $execution->finish(GithubRunnerStatus::Cancelled, 'Stopped externally');
            }

            return Process::result(output: '29.8.0');
        }]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($execution->fresh()->error_message)->toBe('Stopped externally');
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
    });

    it('marks the execution failed and cleans up after the last attempt', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '29.8.0')]);
        fakeRejectedJitConfig();
        runnerTestConfig(runnerTestServer($this->team), $this->githubApp);
        $execution = runnerTestExecution($this->githubApp, ['provision_attempts' => 2]);

        (new ProvisionGithubRunnerJob($execution->id))->handle();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Failed)
            ->and($execution->provision_attempts)->toBe(3)
            ->and($execution->error_message)->toContain('after 3 attempts')->toContain('Resource not accessible by integration');
        Queue::assertPushed(CleanupGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $execution->id);
    });
});

describe('cleanup and reconciliation', function () {
    beforeEach(function () {
        $this->server = runnerTestServer($this->team);
        $this->config = runnerTestConfig($this->server, $this->githubApp, ['idle_timeout' => 10, 'job_timeout' => 60, 'capacity_wait_timeout' => 30]);
    });

    it('removes the containers and the GitHub runner, then provisions the next queued job', function () {
        Queue::fake();
        fakeRunnerGithubApi($this->githubApp);
        Process::fake();
        $execution = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Completed, 'server_id' => $this->server->id, 'runner_id' => 42]);
        $next = runnerTestExecution($this->githubApp);

        (new CleanupGithubRunnerJob($execution->id))->handle();

        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "docker rm -f -v coolify-runner-{$execution->uuid}"));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/orgs/acme/actions/runners/42'));
        Queue::assertPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $next->id);
    });

    it('finishes runners whose container stopped, idled, or ran too long, and removes orphans', function () {
        Queue::fake();
        $stopped = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'started_at' => now()]);
        $idle = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Idle, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'ready_at' => now()->subMinutes(11)]);
        $long = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'started_at' => now()->subMinutes(61)]);
        $healthy = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'started_at' => now()]);

        $lines = [
            "{$stopped->uuid} coolify-runner-{$stopped->uuid} exited",
            "{$idle->uuid} coolify-runner-{$idle->uuid} running",
            "{$long->uuid} coolify-runner-{$long->uuid} running",
            "{$healthy->uuid} coolify-runner-{$healthy->uuid} running",
            "{$healthy->uuid} coolify-runner-{$healthy->uuid}-dind running",
            'orphanuuid coolify-runner-orphanuuid exited',
        ];
        Process::fake(['*' => Process::result(output: implode("\n", $lines))]);

        (new ReconcileGithubRunnersJob)->handle();

        expect($stopped->fresh()->status)->toBe(GithubRunnerStatus::Failed)
            ->and($idle->fresh()->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($long->fresh()->status)->toBe(GithubRunnerStatus::TimedOut)
            ->and($healthy->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertPushed(CleanupGithubRunnerJob::class, 3);
        Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'docker rm -f -v coolify-runner-orphanuuid'));
        Process::assertDidntRun(fn (PendingProcess $process) => str_contains($process->command, "docker rm -f -v coolify-runner-{$healthy->uuid}"));
    });

    it('lets a late completed webhook replace a failure guessed from a stopped container', function () {
        Queue::fake();
        $execution = runnerTestExecution($this->githubApp, [
            'status' => GithubRunnerStatus::Failed,
            'server_id' => $this->server->id,
            'runner_name' => 'coolify-runner-late',
            'error_message' => 'The runner container stopped before GitHub reported a job result.',
        ]);

        sendWorkflowJobWebhook($this, $this->githubApp, 'runner-secret', 'completed', [
            'runner_name' => 'coolify-runner-late',
            'conclusion' => 'success',
        ])->assertOk();

        $execution->refresh();
        expect($execution->status)->toBe(GithubRunnerStatus::Completed)
            ->and($execution->error_message)->toBeNull();
    });

    it('times out queued jobs after the wait timeout and retries the others', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '')]);
        $expired = runnerTestExecution($this->githubApp, ['queued_at' => now()->subMinutes(31)]);
        $waiting = runnerTestExecution($this->githubApp, ['queued_at' => now()->subMinutes(5)]);

        (new ReconcileGithubRunnersJob)->handle();

        expect($expired->fresh()->status)->toBe(GithubRunnerStatus::TimedOut)
            ->and($waiting->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $waiting->id);
        Queue::assertNotPushed(ProvisionGithubRunnerJob::class, fn ($job) => $job->executionId === $expired->id);
    });

    it('does not touch runners when the container list cannot be read', function () {
        Queue::fake();
        $execution = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'started_at' => now()]);
        Process::fake(['*' => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1)]);

        (new ReconcileGithubRunnersJob)->handle();

        expect($execution->fresh()->status)->toBe(GithubRunnerStatus::Running);
        Queue::assertNotPushed(CleanupGithubRunnerJob::class);
    });
});

describe('server deletion', function () {
    beforeEach(function () {
        $this->server = runnerTestServer($this->team);
        $this->config = runnerTestConfig($this->server, $this->githubApp);
    });

    it('deletes the runner configuration and executions of the server and lets the GitHub App be deleted', function () {
        Queue::fake();
        $running = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id, 'runner_id' => 42]);
        $provisioning = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Provisioning, 'server_id' => $this->server->id, 'github_runner_config_id' => $this->config->id]);
        $completed = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Completed, 'server_id' => $this->server->id, 'runner_id' => 41]);
        $otherServer = runnerTestServer($this->team);
        $otherConfig = runnerTestConfig($otherServer, $this->githubApp, ['is_enabled' => false]);
        $other = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Completed, 'server_id' => $otherServer->id]);
        $queued = runnerTestExecution($this->githubApp);

        $this->server->delete();
        DeleteServer::run($this->server->id);

        expect(Server::withTrashed()->find($this->server->id))->toBeNull()
            ->and(GithubRunnerConfig::find($this->config->id))->toBeNull()
            ->and(GithubRunnerExecution::whereKey([$running->id, $provisioning->id, $completed->id])->exists())->toBeFalse()
            ->and($other->fresh())->not->toBeNull()
            ->and($otherConfig->fresh())->not->toBeNull()
            ->and($queued->fresh()->status)->toBe(GithubRunnerStatus::Queued);
        Queue::assertPushed(DeregisterGithubRunnerJob::class, 1);
        Queue::assertPushed(DeregisterGithubRunnerJob::class, fn ($job) => $job->githubAppId === $this->githubApp->id && $job->runnerId === 42);

        $this->githubApp->delete();
        expect(GithubApp::find($this->githubApp->id))->toBeNull();
    });

    it('removes the runner from GitHub and ignores GitHub errors', function (int $status) {
        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/*' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/orgs/acme/actions/runners/42' => Http::response(null, $status),
        ]);

        (new DeregisterGithubRunnerJob($this->githubApp->id, 42))->handle();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/orgs/acme/actions/runners/42'));
    })->with([204, 500]);

    it('removes runners of servers deleted by older versions during reconciliation', function () {
        Queue::fake();
        Process::fake(['*' => Process::result(output: '')]);
        $orphan = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Running, 'runner_id' => 42]);
        $orphanWithoutRunner = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::Provisioning]);
        $queued = runnerTestExecution($this->githubApp);
        $finished = runnerTestExecution($this->githubApp, ['status' => GithubRunnerStatus::TimedOut]);

        (new ReconcileGithubRunnersJob)->handle();

        expect(GithubRunnerExecution::whereKey([$orphan->id, $orphanWithoutRunner->id])->exists())->toBeFalse()
            ->and($queued->fresh()->status)->toBe(GithubRunnerStatus::Queued)
            ->and($finished->fresh()->status)->toBe(GithubRunnerStatus::TimedOut);
        Queue::assertPushed(DeregisterGithubRunnerJob::class, 1);
        Queue::assertPushed(DeregisterGithubRunnerJob::class, fn ($job) => $job->runnerId === 42);

        $this->config->update(['is_enabled' => false]);
        $this->githubApp->delete();
        expect(GithubApp::find($this->githubApp->id))->toBeNull();
    });
});

function fakeRejectedJitConfig(): void
{
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/installations/*' => Http::response(['token' => 'installation-token'], 201),
        'https://api.github.com/orgs/acme/actions/runners/generate-jitconfig' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);
}
