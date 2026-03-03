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

class CleanupGithubRunnerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 3;

    public function backoff(): array
    {
        return [5, 10, 30];
    }

    public function __construct(
        public int $workflowJobId,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        ray("[cleanup] Starting cleanup for workflow_job_id {$this->workflowJobId}");

        $execution = GithubRunnerExecution::where('workflow_job_id', $this->workflowJobId)
            ->with('config.githubApp')
            ->first();

        if (! $execution) {
            ray("[cleanup] No execution found for workflow_job_id {$this->workflowJobId} — skipping");

            return;
        }

        // Already cleaned up
        if (in_array($execution->status, [GithubRunnerStatus::Completed, GithubRunnerStatus::Failed])) {
            ray("[cleanup] Execution {$execution->id} already in {$execution->status->value} — skipping");

            return;
        }

        ray("[cleanup] Execution {$execution->id} transitioning from {$execution->status->value} → cleaning");
        $execution->update(['status' => GithubRunnerStatus::Cleaning]);

        try {
            $server = $execution->server;

            if (! $server || ! $server->isFunctional()) {
                ray("[cleanup] Server not functional for execution {$execution->id}; marking failed to release capacity");
                $this->deregisterFromGithub($execution);

                $execution->update([
                    'status' => GithubRunnerStatus::Failed,
                    'error_message' => 'Cleanup skipped: server is not functional.',
                    'completed_at' => now(),
                ]);

                return;
            }

            if ($execution->pid) {
                instant_remote_process([
                    "kill {$execution->pid} 2>/dev/null || true",
                ], $server, throwError: false);
            }

            if ($execution->runner_dir) {
                instant_remote_process([
                    "rm -rf {$execution->runner_dir}",
                ], $server, throwError: false);
            }

            $this->deregisterFromGithub($execution);

            $execution->update([
                'status' => GithubRunnerStatus::Completed,
                'completed_at' => now(),
            ]);

            ray("[cleanup] Execution {$execution->id} completed successfully");
        } catch (\Throwable $e) {
            ray("[cleanup] Execution {$execution->id} cleanup failed: {$e->getMessage()}");
            $execution->update([
                'status' => GithubRunnerStatus::Failed,
                'error_message' => 'Cleanup failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        GithubRunnerExecution::query()
            ->where('workflow_job_id', $this->workflowJobId)
            ->whereIn('status', [
                GithubRunnerStatus::Queued,
                GithubRunnerStatus::Provisioning,
                GithubRunnerStatus::Running,
                GithubRunnerStatus::Cleaning,
            ])
            ->update([
                'status' => GithubRunnerStatus::Failed,
                'error_message' => 'Cleanup failed: '.($exception?->getMessage() ?? 'unknown error'),
                'completed_at' => now(),
            ]);
    }

    private function deregisterFromGithub(GithubRunnerExecution $execution): void
    {
        if (! $execution->runner_id) {
            ray('Runner deregister skipped: no runner_id for execution '.$execution->id);

            return;
        }

        $githubApp = $execution->config?->githubApp;
        if (! $githubApp || $githubApp->is_public) {
            ray('Runner deregister skipped: no githubApp or is_public for execution '.$execution->id);

            return;
        }

        $org = $githubApp->organization;
        if (! $org) {
            ray('Runner deregister skipped: no organization for execution '.$execution->id);

            return;
        }

        try {
            $token = generateGithubInstallationToken($githubApp);
            $apiUrl = $githubApp->api_url ?? 'https://api.github.com';

            ray("Deregistering runner {$execution->runner_id} from {$org} via DELETE /orgs/{$org}/actions/runners/{$execution->runner_id}");

            $response = Http::GitHub($apiUrl, $token)
                ->delete("/orgs/{$org}/actions/runners/{$execution->runner_id}");

            ray('Runner deregister response: '.$response->status().' '.$response->body());
        } catch (\Throwable $e) {
            ray('Runner deregister failed: '.$e->getMessage());
        }
    }
}
