<?php

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use Lorisleiva\Actions\Concerns\AsAction;

class CleanupPreviewDeployment
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * Clean up a PR preview deployment completely.
     *
     * This handles:
     * 1. Cancelling active deployments for the PR (QUEUED/IN_PROGRESS → CANCELLED_BY_USER)
     * 2. Killing helper containers by deployment_uuid
     * 3. Stopping/removing all running PR containers
     * 4. Dispatching DeleteResourceJob for thorough cleanup (volumes, networks, database records)
     *
     * This unifies the cleanup logic from GitHub webhook handler to be used across all providers.
     */
    public function handle(
        Application $application,
        int $pull_request_id,
        ?ApplicationPreview $preview = null
    ): array {
        $result = [
            'cancelled_deployments' => 0,
            'killed_containers' => 0,
            'status' => 'success',
        ];

        $server = $application->destination->server;

        if (! $server->isFunctional()) {
            return [
                ...$result,
                'status' => 'failed',
                'message' => 'Server is not functional',
            ];
        }

        // Step 1: Cancel all active deployments for this PR and kill helper containers
        $result['cancelled_deployments'] = $this->cancelActiveDeployments(
            $application,
            $pull_request_id,
            $server
        );

        // Step 2: Stop and remove all running PR containers
        $result['killed_containers'] = $this->stopRunningContainers(
            $application,
            $pull_request_id,
            $server
        );

        // Step 3: Find or use provided preview, then dispatch cleanup job for thorough cleanup
        if (! $preview) {
            $preview = ApplicationPreview::where('application_id', $application->id)
                ->where('pull_request_id', $pull_request_id)
                ->first();
        }

        if ($preview) {
            DeleteResourceJob::dispatch($preview);
        }

        return $result;
    }

    /**
     * Cancel all active (QUEUED/IN_PROGRESS) deployments for this PR.
     */
    private function cancelActiveDeployments(
        Application $application,
        int $pull_request_id,
        $server
    ): int {
        $activeDeployments = ApplicationDeploymentQueue::where('application_id', $application->id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->get();

        $cancelled = 0;
        foreach ($activeDeployments as $deployment) {
            try {
                // Mark deployment as cancelled
                $deployment->update([
                    'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ]);

                // Add cancellation log entry
                $deployment->addLogEntry('Deployment cancelled: Pull request closed.', 'stderr');

                // Try to kill helper container if it exists
                $this->killHelperContainer($deployment->deployment_uuid, $server);
                $cancelled++;
            } catch (\Throwable $e) {
                \Log::warning("Failed to cancel deployment {$deployment->id}: {$e->getMessage()}");
            }
        }

        return $cancelled;
    }

    /**
     * Kill the helper container used during deployment.
     */
    private function killHelperContainer(string $deployment_uuid, $server): void
    {
        try {
            $escapedUuid = escapeshellarg($deployment_uuid);
            $checkCommand = "docker ps -a --filter name={$escapedUuid} --format '{{.Names}}'";
            $containerExists = instant_remote_process([$checkCommand], $server);

            if ($containerExists && str($containerExists)->trim()->isNotEmpty()) {
                instant_remote_process(["docker rm -f {$escapedUuid}"], $server);
            }
        } catch (\Throwable $e) {
            // Silently handle - container may already be gone
        }
    }

    /**
     * Stop and remove all running containers for this PR.
     */
    private function stopRunningContainers(
        Application $application,
        int $pull_request_id,
        $server
    ): int {
        $killed = 0;

        try {
            if ($server->isSwarm()) {
                $escapedStackName = escapeshellarg("{$application->uuid}-{$pull_request_id}");
                instant_remote_process(["docker stack rm {$escapedStackName}"], $server);
                $killed++;
            } else {
                $containers = getCurrentApplicationContainerStatus(
                    $server,
                    $application->id,
                    $pull_request_id
                );

                if ($containers->isNotEmpty()) {
                    foreach ($containers as $container) {
                        $containerName = data_get($container, 'Names');
                        if ($containerName) {
                            $escapedContainerName = escapeshellarg($containerName);
                            instant_remote_process(
                                ["docker rm -f {$escapedContainerName}"],
                                $server
                            );
                            $killed++;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning("Error stopping containers for PR #{$pull_request_id}: {$e->getMessage()}");
        }

        return $killed;
    }
}
