<?php

namespace App\Jobs;

use App\Models\GitHubRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupGithubRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public GitHubRunner $runner
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            Log::info('Cleaning up runner', [
                'runner_id' => $this->runner->id,
                'container_name' => $this->runner->container_name,
            ]);

            // The Docker container should auto-remove itself (--rm flag)
            // But we'll explicitly try to remove it in case it's still there
            if ($this->runner->container_name && $this->runner->server) {
                $command = sprintf(
                    'docker rm -f %s 2>/dev/null || true',
                    escapeshellarg($this->runner->container_name)
                );

                instant_remote_process([$command], $this->runner->server, false);

                Log::info('Docker container cleanup command executed', [
                    'container_name' => $this->runner->container_name,
                    'server_id' => $this->runner->server_id,
                ]);
            }

            // Note: We keep the runner record in the database for history
            // Old records can be cleaned up by a separate scheduled job if needed

            Log::info('Runner cleanup completed', [
                'runner_id' => $this->runner->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error cleaning up runner', [
                'runner_id' => $this->runner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
