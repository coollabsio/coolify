<?php

namespace App\Actions\V5\Application;

use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\ContainerState;
use App\Enums\V5\ServerStatus;
use App\Models\V5\Application;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class DeployNginxApplication
{
    use AsAction;

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Application $application): Application
    {
        $application->loadMissing('server');
        $server = $application->server;

        if ($server === null) {
            return $this->markFailed($application, 'No server is attached to this application.');
        }

        if ($server->status !== ServerStatus::Installed->value || $server->last_bootstrapped_at === null) {
            return $this->markFailed($application, "Bootstrap server {$server->name} before deploying to it.");
        }

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            return $this->markFailed($application, 'No Flux host ID is available for this server.');
        }

        $containerId = null;

        try {
            $this->fluxClient->pullImage($hostId, $application->image);
            $containerId = $this->fluxClient->createContainer($hostId, $this->containerSpec($application));

            // Persist the runtime id the instant the container exists, before
            // start/inspect. A worker SIGKILL at the job timeout would otherwise
            // orphan a created container whose id only lived in this local var,
            // leaving failed()/reconcile unable to find and clean it by id.
            $application->update([
                'status' => ApplicationStatus::Created->value,
                'status_message' => 'Container created.',
                'runtime_container_id' => $containerId,
            ]);

            $this->fluxClient->startContainer($hostId, $containerId);
            $inspect = $this->fluxClient->inspectContainer($hostId, $containerId);

            if (! $this->isContainerRunning($inspect)) {
                $this->cleanUpContainer($application, $hostId, $containerId);

                return $this->markFailed($application, 'Container did not stay running.');
            }

            $application->update([
                'status' => ApplicationStatus::Running->value,
                'status_message' => 'Container started.',
                'runtime_container_id' => $containerId,
            ]);

            return $application->refresh()->load('server');
        } catch (\Throwable $e) {
            if (is_string($containerId) && $containerId !== '') {
                $this->cleanUpContainer($application, $hostId, $containerId);
            }

            return $this->markFailed($application, $e->getMessage());
        }
    }

    /**
     * Best-effort compensation for a failed deploy: stop and force-remove the
     * container this run created so it is never left orphaned on the node, then
     * null the runtime id we persisted right after create so a cleaned-up
     * failure never leaves a dangling id that reconcile would try to reap.
     * Cleanup failures only log a warning and never mask the original error.
     */
    private function cleanUpContainer(Application $application, string $hostId, string $containerId): void
    {
        try {
            $this->fluxClient->stopContainer($hostId, $containerId);
        } catch (\Throwable $e) {
            Log::warning('Could not stop the container created by a failed v5 deploy.', [
                'application_id' => $application->getKey(),
                'container_id' => $containerId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->fluxClient->removeContainer($hostId, $containerId, force: true);
        } catch (\Throwable $e) {
            Log::warning('Could not remove the container created by a failed v5 deploy.', [
                'application_id' => $application->getKey(),
                'container_id' => $containerId,
                'error' => $e->getMessage(),
            ]);
        }

        if ($application->runtime_container_id === $containerId) {
            $application->update(['runtime_container_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function containerSpec(Application $application): array
    {
        $network = $this->meshNetwork($application);
        $containerName = $application->container_name;

        return [
            'name' => $containerName,
            'image' => $application->image,
            'networks' => [$network],
            'network_aliases' => [$containerName],
            'dns_search' => [$this->meshDnsSearchDomain($application)],
            'restart_policy' => 'unless-stopped',
        ];
    }

    private function meshNetwork(Application $application): string
    {
        $namespace = $application->mesh_namespace ?: 'default';

        return "coolify-{$namespace}-mesh";
    }

    private function meshDnsSearchDomain(Application $application): string
    {
        $namespace = $application->mesh_namespace ?: 'default';

        return "{$namespace}.coolify.internal";
    }

    /**
     * @param  array<string, mixed>  $inspect
     */
    private function isContainerRunning(array $inspect): bool
    {
        $state = $inspect['State'] ?? [];

        if (is_array($state) && ($state['Running'] ?? null) === true) {
            return true;
        }

        return is_string($inspect['state'] ?? null) && $inspect['state'] === ContainerState::Running->value;
    }

    private function markFailed(Application $application, string $message): Application
    {
        $application->update([
            'status' => ApplicationStatus::Failed->value,
            'status_message' => str($message)->limit(10000)->toString(),
        ]);

        return $application->refresh()->load('server');
    }
}
