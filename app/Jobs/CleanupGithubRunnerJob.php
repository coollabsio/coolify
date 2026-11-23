<?php

namespace App\Jobs;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubRunnerExecution;
use App\Services\GithubRunner\GithubRunnerApi;
use App\Services\GithubRunner\GithubRunnerContainer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Removes the Docker resources of a finished runner, cancels the workflow run of a job that it did
 * not finish, removes the runner from GitHub,
 * and gives the free capacity to the next queued execution.
 */
class CleanupGithubRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function __construct(public int $executionId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $execution = GithubRunnerExecution::query()->with(['server', 'githubApp'])->find($this->executionId);
        if (! $execution) {
            return;
        }

        // Another Coolify instance manages a transferred server and its runners: never touch them from here.
        if ($execution->server?->isTransferredAway()) {
            Log::info('Skipped GitHub runner cleanup: the server was transferred to another instance', ['execution' => $execution->uuid]);
            ProvisionGithubRunnerJob::dispatchQueued($execution->github_app_id);

            return;
        }

        if ($execution->server) {
            instant_remote_process(GithubRunnerContainer::cleanupCommands($execution->uuid), $execution->server, false);
        }

        if ($execution->githubApp && $this->jobMayStillRun($execution)) {
            try {
                (new GithubRunnerApi($execution->githubApp))->cancelUnfinishedWorkflowRun($execution->repository_full_name, $execution->workflow_job_id);
            } catch (\Throwable $e) {
                Log::warning('Could not cancel the workflow run of a stopped GitHub runner', ['execution' => $execution->uuid, 'error' => $e->getMessage()]);
            }
        }

        if ($execution->runner_id && $execution->githubApp) {
            try {
                (new GithubRunnerApi($execution->githubApp))->deleteRunner($execution->runner_id);
            } catch (\Throwable $e) {
                // GitHub refuses to remove a runner while it has a job, for example until a cancelled run ends.
                DeregisterGithubRunnerJob::dispatch($execution->github_app_id, $execution->runner_id)
                    ->delay(now()->addSeconds(DeregisterGithubRunnerJob::RETRY_DELAY_SECONDS));
            }
        }

        ProvisionGithubRunnerJob::dispatchQueued($execution->github_app_id);
    }

    /**
     * A job was assigned to the runner and GitHub did not report its result, so the job stays
     * in progress on GitHub after Coolify removes the runner (failed container, timeout, or cancel).
     */
    private function jobMayStillRun(GithubRunnerExecution $execution): bool
    {
        return $execution->status !== GithubRunnerStatus::Completed
            && $execution->started_at !== null
            && $execution->workflow_job_id !== null
            && filled($execution->repository_full_name);
    }
}
