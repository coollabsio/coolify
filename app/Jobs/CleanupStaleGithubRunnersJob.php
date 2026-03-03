<?php

namespace App\Jobs;

use App\Enums\GithubRunnerStatus;
use App\Models\GithubRunnerExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CleanupStaleGithubRunnersJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1;

    public function __construct()
    {
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $this->cleanupDeadRunners();
        $this->cleanupStaleRunners();
    }

    /**
     * Check Running executions with a PID and mark them Failed if the process no longer exists.
     * Uses a 5-minute grace period to avoid false-positives during startup.
     */
    private function cleanupDeadRunners(): void
    {
        $gracePeriod = now()->subMinutes(5);

        $runningExecutions = GithubRunnerExecution::query()
            ->where('status', GithubRunnerStatus::Running)
            ->whereNotNull('pid')
            ->where('started_at', '<', $gracePeriod)
            ->with(['server', 'config.githubApp'])
            ->get();

        foreach ($runningExecutions as $execution) {
            try {
                $server = $execution->server;

                if (! $server->isFunctional()) {
                    continue;
                }

                // kill -0 checks if the process exists without sending a signal.
                // Exit code 0 = alive, non-zero = dead.
                $result = instant_remote_process([
                    "kill -0 {$execution->pid} 2>/dev/null && echo alive || echo dead",
                ], $server, throwError: false);

                if (trim((string) $result) !== 'alive') {
                    if ($execution->runner_dir) {
                        instant_remote_process([
                            "rm -rf {$execution->runner_dir}",
                        ], $server, throwError: false);
                    }

                    $this->deregisterFromGithub($execution);

                    $execution->update([
                        'status' => GithubRunnerStatus::Failed,
                        'error_message' => 'Runner process died unexpectedly.',
                        'completed_at' => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                $execution->update([
                    'status' => GithubRunnerStatus::Failed,
                    'error_message' => 'Health check failed: '.$e->getMessage(),
                    'completed_at' => now(),
                ]);
            }
        }
    }

    /**
     * Mark any active executions older than 2 hours as timed out.
     */
    private function cleanupStaleRunners(): void
    {
        $staleThreshold = now()->subHours(2);

        $staleExecutions = GithubRunnerExecution::query()
            ->whereIn('status', [
                GithubRunnerStatus::Queued,
                GithubRunnerStatus::Provisioning,
                GithubRunnerStatus::Running,
                GithubRunnerStatus::Cleaning,
            ])
            ->where('created_at', '<', $staleThreshold)
            ->with(['server', 'config.githubApp'])
            ->get();

        foreach ($staleExecutions as $execution) {
            try {
                $server = $execution->server;

                if ($execution->pid && $server->isFunctional()) {
                    instant_remote_process([
                        "kill {$execution->pid} 2>/dev/null || true",
                    ], $server, throwError: false);
                }

                if ($execution->runner_dir && $server->isFunctional()) {
                    instant_remote_process([
                        "rm -rf {$execution->runner_dir}",
                    ], $server, throwError: false);
                }

                $this->deregisterFromGithub($execution);

                $execution->update([
                    'status' => GithubRunnerStatus::TimedOut,
                    'error_message' => 'Runner exceeded maximum execution time (2 hours).',
                    'completed_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $execution->update([
                    'status' => GithubRunnerStatus::TimedOut,
                    'error_message' => 'Stale cleanup failed: '.$e->getMessage(),
                    'completed_at' => now(),
                ]);
            }
        }
    }

    private function deregisterFromGithub(GithubRunnerExecution $execution): void
    {
        if (! $execution->runner_id) {
            return;
        }

        $githubApp = $execution->config?->githubApp;
        if (! $githubApp || $githubApp->is_public) {
            return;
        }

        $org = $githubApp->organization;
        if (! $org) {
            return;
        }

        try {
            $token = generateGithubInstallationToken($githubApp);
            $apiUrl = $githubApp->api_url ?? 'https://api.github.com';

            Http::GitHub($apiUrl, $token)
                ->delete("/orgs/{$org}/actions/runners/{$execution->runner_id}");
        } catch (\Throwable) {
            // Best-effort: don't block cleanup if the API call fails
        }
    }
}
