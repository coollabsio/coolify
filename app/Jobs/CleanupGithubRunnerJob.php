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
        $execution = GithubRunnerExecution::where('workflow_job_id', $this->workflowJobId)
            ->with('config.githubApp')
            ->first();

        if (! $execution) {
            return;
        }

        // Already cleaned up
        if (in_array($execution->status, [GithubRunnerStatus::Completed, GithubRunnerStatus::Failed])) {
            return;
        }

        $execution->update(['status' => GithubRunnerStatus::Cleaning]);

        try {
            $server = $execution->server;

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
        } catch (\Throwable $e) {
            $execution->update([
                'status' => GithubRunnerStatus::Failed,
                'error_message' => 'Cleanup failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
        }
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
