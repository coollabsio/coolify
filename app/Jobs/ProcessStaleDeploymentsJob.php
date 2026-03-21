<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Process stale deployments that are stuck in 'queued' status.
 *
 * Runs every minute via the scheduler. Finds deployments that have been
 * queued for longer than the stale threshold and starts them if the
 * server has capacity (respects concurrent_builds limit).
 *
 * This prevents queue starvation — previously, queued deployments only
 * advanced when a new deployment triggered next_queuable().
 */
class ProcessStaleDeploymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const STALE_THRESHOLD_MINUTES = 2;

    public function handle(): void
    {
        $staleDeployments = ApplicationDeploymentQueue::where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->where('created_at', '<=', Carbon::now()->subMinutes(self::STALE_THRESHOLD_MINUTES))
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($staleDeployments as $deployment) {
            $serverId = $deployment->server_id;
            $server = Server::find($serverId);

            if (! $server) {
                continue;
            }

            $concurrentLimit = $server->settings->concurrent_builds ?? 1;
            $inProgressCount = ApplicationDeploymentQueue::where('server_id', $serverId)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->count();

            if ($inProgressCount < $concurrentLimit) {
                $deployment->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                ]);

                ApplicationDeploymentJob::dispatch(
                    application_deployment_queue_id: $deployment->id,
                );

                \Log::info("ProcessStaleDeployments: Started stale deployment {$deployment->deployment_uuid} (queued for ".
                    Carbon::parse($deployment->created_at)->diffForHumans().")");
            }
        }
    }
}
