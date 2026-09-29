<?php

namespace App\Jobs;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Services\GithubRunner\GithubRunnerApi;
use App\Services\GithubRunner\GithubRunnerContainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Starts one ephemeral runner for a queued execution on the least busy matching build server.
 * When no server has free capacity, the execution stays queued and is retried by
 * CleanupGithubRunnerJob or ReconcileGithubRunnersJob. A runner that fails to start is removed and
 * started again, possibly on another server, up to MAX_ATTEMPTS times.
 */
class ProvisionGithubRunnerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 900;

    private const DOCKER_READY_ATTEMPTS = 60;

    public const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(public int $executionId)
    {
        $this->onQueue('high');
    }

    /**
     * Starts provisioning for the oldest queued executions of a GitHub App.
     */
    public static function dispatchQueued(int $githubAppId, int $limit = 10): void
    {
        GithubRunnerExecution::query()
            ->where('github_app_id', $githubAppId)
            ->where('status', GithubRunnerStatus::Queued)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(fn (int $id) => self::dispatch($id));
    }

    public function handle(): void
    {
        $execution = $this->reserveServer();
        if (! $execution) {
            return;
        }

        try {
            $config = $execution->config;
            $server = $execution->server;
            $api = new GithubRunnerApi($execution->githubApp);
            $runnerGroupId = $execution->githubApp->runner_group_id ?: $api->ensureRunnerGroup()['id'];
            $container = new GithubRunnerContainer($execution, $config);

            instant_remote_process($container->prepareCommands(), $server, timeout: $this->timeout);
            if ($container->usesDind()) {
                $this->waitForDocker($container, $server);
            }

            $runner = $api->generateJitConfig($container->name(), $config->registeredLabels(), $runnerGroupId);
            $execution->update(['runner_id' => $runner['runner_id']]);
            instant_remote_process($container->startCommands($runner['encoded_jit_config']), $server);

            $execution->update(['ready_at' => now()]);
            GithubRunnerExecution::query()
                ->whereKey($execution->id)
                ->where('status', GithubRunnerStatus::Provisioning)
                ->update(['status' => GithubRunnerStatus::Idle]);
        } catch (\Throwable $e) {
            $this->handleFailure($execution, $e);
        }
    }

    /**
     * Removes the failed runner and queues the job again, or marks it failed after the last attempt.
     * GitHub keeps the job queued, so a later runner with matching labels can still take it.
     */
    private function handleFailure(GithubRunnerExecution $execution, \Throwable $e): void
    {
        $attempt = $execution->provision_attempts;
        if ($attempt >= self::MAX_ATTEMPTS) {
            $execution->finish(GithubRunnerStatus::Failed, "Could not start a runner after {$attempt} attempts: {$e->getMessage()}");
            CleanupGithubRunnerJob::dispatch($execution->id);

            return;
        }

        // The retry keeps the execution uuid, so its Docker resources must be gone before it starts again.
        (new CleanupGithubRunnerJob($execution->id))->handle();
        $execution->update([
            'status' => GithubRunnerStatus::Queued,
            'github_runner_config_id' => null,
            'server_id' => null,
            'runner_name' => null,
            'runner_id' => null,
            'ready_at' => null,
            'error_message' => "Attempt {$attempt} of ".self::MAX_ATTEMPTS." failed: {$e->getMessage()}",
        ]);
        self::dispatch($execution->id)->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS * $attempt));
    }

    /**
     * Picks the least busy matching server and moves the execution to "provisioning".
     * Row locks on the execution and on the App's configs stop parallel jobs from overbooking a server.
     */
    private function reserveServer(): ?GithubRunnerExecution
    {
        return DB::transaction(function () {
            $execution = GithubRunnerExecution::query()->whereKey($this->executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== GithubRunnerStatus::Queued) {
                return null;
            }

            $config = GithubRunnerConfig::query()
                ->where('github_app_id', $execution->github_app_id)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('server.settings')
                ->get()
                ->filter(fn (GithubRunnerConfig $config) => $config->server !== null
                    && $config->matchesLabels($execution->labels ?? [])
                    && $config->server->isBuildServer()
                    && ! $config->server->isLocalhost()
                    && $config->server->isFunctional())
                ->map(fn (GithubRunnerConfig $config) => ['config' => $config, 'occupied' => $config->occupiedRunnerCount()])
                ->filter(fn (array $candidate) => $candidate['occupied'] < $candidate['config']->max_runners)
                ->sortBy('occupied')
                ->first()['config'] ?? null;

            if (! $config) {
                return null;
            }

            $execution->update([
                'github_runner_config_id' => $config->id,
                'server_id' => $config->server_id,
                'status' => GithubRunnerStatus::Provisioning,
                'runner_name' => GithubRunnerContainer::nameFor($execution->uuid),
                'provision_attempts' => $execution->provision_attempts + 1,
            ]);

            return $execution;
        });
    }

    private function waitForDocker(GithubRunnerContainer $container, Server $server): void
    {
        for ($attempt = 0; $attempt < self::DOCKER_READY_ATTEMPTS; $attempt++) {
            if (filled(instant_remote_process([$container->dockerReadyCommand()], $server, false))) {
                return;
            }
            Sleep::for(1)->second();
        }

        throw new RuntimeException('The Docker sidecar did not start within '.self::DOCKER_READY_ATTEMPTS.' seconds.');
    }
}
