<?php

namespace App\Actions\V5\Application;

use App\Models\V5\Application;
use App\Services\Flux\FluxClient;
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

        $hostId = $server->wireguard_management_ip ?: $server->node_address ?: $server->host;

        if (! is_string($hostId) || $hostId === '') {
            return $this->markFailed($application, 'No Flux host ID is available for this server.');
        }

        try {
            $this->fluxClient->pullImage($hostId, $application->image);
            $containerId = $this->fluxClient->createContainer($hostId, $this->containerSpec($application));
            $this->fluxClient->startContainer($hostId, $containerId);
            $inspect = $this->fluxClient->inspectContainer($hostId, $containerId);

            if (! $this->isContainerRunning($inspect)) {
                return $this->markFailed($application, 'Container did not stay running.');
            }

            $application->update([
                'status' => 'running',
                'status_message' => 'Container started.',
                'runtime_container_id' => $containerId,
            ]);

            return $application->refresh()->load('server');
        } catch (\Throwable $e) {
            return $this->markFailed($application, $e->getMessage());
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

        return is_string($inspect['state'] ?? null) && $inspect['state'] === 'running';
    }

    private function markFailed(Application $application, string $message): Application
    {
        $application->update([
            'status' => 'failed',
            'status_message' => str($message)->limit(10000)->toString(),
        ]);

        return $application->refresh()->load('server');
    }
}
