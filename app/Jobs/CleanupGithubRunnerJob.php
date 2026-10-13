<?php

namespace App\Jobs;

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
 * Removes the Docker resources of a finished runner, removes the runner from GitHub,
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

        if ($execution->server) {
            instant_remote_process(GithubRunnerContainer::cleanupCommands($execution->uuid), $execution->server, false);
        }

        if ($execution->runner_id && $execution->githubApp) {
            try {
                (new GithubRunnerApi($execution->githubApp))->deleteRunner($execution->runner_id);
            } catch (\Throwable $e) {
                Log::warning('Could not remove GitHub runner', ['execution' => $execution->uuid, 'error' => $e->getMessage()]);
            }
        }

        ProvisionGithubRunnerJob::dispatchQueued($execution->github_app_id);
    }
}
