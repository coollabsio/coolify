<?php

namespace App\Jobs;

use App\Actions\Application\CleanupCancelledDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Stops the build of a running deployment that a newer webhook deployment replaced, then starts the
 * queued deployments. It runs on the queue, because one push can replace builds of many applications
 * and the webhook request must answer fast.
 */
class CancelSupersededDeploymentJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public ApplicationDeploymentQueue $deployment, public int $teamId)
    {
        $this->onQueue(deployment_queue());
    }

    public function handle(): void
    {
        try {
            CleanupCancelledDeployment::run($this->deployment, $this->teamId);
        } catch (Throwable $e) {
            \Log::warning("Failed to clean up superseded deployment {$this->deployment->deployment_uuid}: {$e->getMessage()}");
        } finally {
            if ($this->deployment->application) {
                queue_next_deployment($this->deployment->application, $this->deployment->server_id);
            }
        }
    }
}
