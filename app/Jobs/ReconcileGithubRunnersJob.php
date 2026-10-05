<?php

namespace App\Jobs;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Services\GithubRunner\GithubRunnerContainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Compares runner executions with the runner containers on each build server. It cleans up
 * stopped and orphaned runners, applies the wait, idle, and job timeouts, and restarts
 * provisioning for queued executions whose dispatch was lost.
 */
class ReconcileGithubRunnersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    private const PROVISIONING_TIMEOUT_MINUTES = 20;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(): void
    {
        Server::query()
            ->whereHas('githubRunnerConfig')
            ->with('settings')
            ->get()
            ->filter(fn (Server $server) => $server->isFunctional())
            ->each(fn (Server $server) => $this->reconcileServer($server));

        $this->removeRunnersOfDeletedServers();
        $this->reconcileQueued();
    }

    /**
     * Older versions left occupying executions of a deleted server active, which blocked deleting the App.
     */
    private function removeRunnersOfDeletedServers(): void
    {
        GithubRunnerExecution::deleteAndDeregister(
            GithubRunnerExecution::query()
                ->whereNull('server_id')
                ->whereIn('status', GithubRunnerStatus::occupying())
        );
    }

    private function reconcileServer(Server $server): void
    {
        $output = instant_remote_process([GithubRunnerContainer::listCommand()], $server, false);
        if ($output === null) {
            return;
        }
        $containers = $this->parseContainers($output);

        $executions = GithubRunnerExecution::query()
            ->where('server_id', $server->id)
            ->whereIn('status', GithubRunnerStatus::occupying())
            ->with('config')
            ->get();

        foreach ($executions as $execution) {
            $failure = $this->failureFor($execution, $containers->get($execution->uuid, []));
            if ($failure) {
                $execution->finish(...$failure);
                CleanupGithubRunnerJob::dispatch($execution->id);
            }
        }

        $containers->keys()
            ->diff($executions->pluck('uuid'))
            ->each(fn (string $uuid) => instant_remote_process(GithubRunnerContainer::cleanupCommands($uuid), $server, false));
    }

    /**
     * @param  array<string, string>  $containerStates  container name => state
     * @return array{0: GithubRunnerStatus, 1: string}|null
     */
    private function failureFor(GithubRunnerExecution $execution, array $containerStates): ?array
    {
        if ($execution->status === GithubRunnerStatus::Provisioning) {
            return $execution->updated_at->lt(now()->subMinutes(self::PROVISIONING_TIMEOUT_MINUTES))
                ? [GithubRunnerStatus::Failed, 'Provisioning did not finish in '.self::PROVISIONING_TIMEOUT_MINUTES.' minutes.']
                : null;
        }

        $state = $containerStates[GithubRunnerContainer::nameFor($execution->uuid)] ?? null;
        if (! in_array($state, ['running', 'restarting', 'paused'], true)) {
            return [GithubRunnerStatus::Failed, 'The runner container stopped before GitHub reported a job result.'];
        }

        $config = $execution->config;
        if ($execution->status === GithubRunnerStatus::Idle && $config
            && ($execution->ready_at ?? $execution->updated_at)->lt(now()->subMinutes($config->idle_timeout))) {
            return [GithubRunnerStatus::Cancelled, "No job was assigned to the runner within {$config->idle_timeout} minutes."];
        }

        if ($execution->status === GithubRunnerStatus::Running && $config
            && ($execution->started_at ?? $execution->updated_at)->lt(now()->subMinutes($config->job_timeout))) {
            return [GithubRunnerStatus::TimedOut, "The job ran longer than {$config->job_timeout} minutes."];
        }

        return null;
    }

    /**
     * @return Collection<string, array<string, string>> execution uuid => [container name => state]
     */
    private function parseContainers(string $output): Collection
    {
        $containers = collect();
        foreach (preg_split('/\R/', trim($output)) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 3 || ! preg_match('/\A[a-z0-9]+\z/', $parts[0])) {
                continue;
            }
            [$uuid, $name, $state] = $parts;
            $containers->put($uuid, [...$containers->get($uuid, []), $name => $state]);
        }

        return $containers;
    }

    private function reconcileQueued(): void
    {
        $queued = GithubRunnerExecution::query()
            ->where('status', GithubRunnerStatus::Queued)
            ->get()
            ->groupBy('github_app_id');

        foreach ($queued as $githubAppId => $executions) {
            $timeout = (int) (GithubRunnerConfig::query()
                ->where('github_app_id', $githubAppId)
                ->where('is_enabled', true)
                ->max('capacity_wait_timeout') ?? 0);

            foreach ($executions as $execution) {
                if (($execution->queued_at ?? $execution->created_at)->lt(now()->subMinutes($timeout))) {
                    $execution->finish(GithubRunnerStatus::TimedOut, "No build server had free capacity within {$timeout} minutes.");
                }
            }

            ProvisionGithubRunnerJob::dispatchQueued($githubAppId);
        }
    }
}
