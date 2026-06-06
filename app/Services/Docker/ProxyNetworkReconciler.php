<?php

namespace App\Services\Docker;

use App\Exceptions\ProxyNetworkReconciliationException;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\StandaloneDocker;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use Closure;

class ProxyNetworkReconciler
{
    use ExecutesDockerCommands;

    public function __construct(
        private readonly DockerNetworkInspector $inspector = new DockerNetworkInspector,
        private readonly ?Closure $executor = null,
    ) {}

    public function enable(DockerNetwork $network): DockerNetwork
    {
        $this->assertEligible($network);
        $runtime = $this->inspect($network);

        if (! $this->proxyIsAttached($runtime)) {
            $this->run($network, 'docker network connect '.escapeshellarg($network->docker_network_name).' '.escapeshellarg($this->proxyContainerName($network)));
            $runtime = $this->inspect($network);
        }

        if (! $this->proxyIsAttached($runtime)) {
            throw new ProxyNetworkReconciliationException('Coolify proxy could not be connected to this network.');
        }

        $network->update(['proxy_access' => true]);
        $this->persistRuntimeState($network, $runtime);

        return $network->refresh();
    }

    public function disable(DockerNetwork $network): DockerNetwork
    {
        $dependency = $this->activeDependency($network);

        if ($dependency !== null) {
            throw new ProxyNetworkReconciliationException("Proxy access is required by active resource: {$dependency}.");
        }

        $runtime = $this->inspect($network);

        if ($this->proxyIsAttached($runtime)) {
            $this->run($network, 'docker network disconnect '.escapeshellarg($network->docker_network_name).' '.escapeshellarg($this->proxyContainerName($network)));
            $runtime = $this->inspect($network);
        }

        if ($this->proxyIsAttached($runtime)) {
            throw new ProxyNetworkReconciliationException('Coolify proxy could not be disconnected from this network.');
        }

        $network->update(['proxy_access' => false]);
        $this->persistRuntimeState($network, $runtime);

        return $network->refresh();
    }

    public function detectDrift(DockerNetwork $network): DockerNetwork
    {
        $runtime = $this->inspect($network);

        if ($network->proxy_access === null) {
            $network->update(['proxy_access' => $this->proxyIsAttached($runtime)]);
            $network->refresh();
        }

        $this->persistRuntimeState($network, $runtime);

        return $network->refresh();
    }

    public function isReconciled(DockerNetwork $network): bool
    {
        return (bool) data_get($network->last_inspect_data, 'proxy_access_reconciled', false);
    }

    public function runtimeProxyAccess(DockerNetwork $network): bool
    {
        return (bool) data_get($network->last_inspect_data, 'proxy_access_runtime', false);
    }

    private function assertEligible(DockerNetwork $network): void
    {
        if (! $network->is_active || $network->is_system) {
            throw new ProxyNetworkReconciliationException('Proxy access is unavailable for system or inactive networks.');
        }
    }

    private function inspect(DockerNetwork $network): array
    {
        $raw = $this->inspector->rawInspect($network->server, $network->docker_network_name, $this->executor);

        if ($raw === null) {
            throw new ProxyNetworkReconciliationException('Docker network inspect failed.');
        }

        return $this->inspector->normalize($raw);
    }

    private function run(DockerNetwork $network, string $command): void
    {
        if ($this->executeDocker($network->server, [$command], $this->executor) === null) {
            throw new ProxyNetworkReconciliationException('Docker proxy network operation failed.');
        }
    }

    private function proxyIsAttached(array $runtime): bool
    {
        return collect(data_get($runtime, 'containers', []))
            ->contains(fn (array $container) => ltrim((string) data_get($container, 'Name'), '/') === 'coolify-proxy');
    }

    private function proxyContainerName(DockerNetwork $network): string
    {
        if ($network->server->isSwarm()) {
            throw new ProxyNetworkReconciliationException('Explicit proxy network reconciliation is not supported for Swarm networks yet.');
        }

        return 'coolify-proxy';
    }

    private function persistRuntimeState(DockerNetwork $network, array $runtime): void
    {
        $runtimeAccess = $this->proxyIsAttached($runtime);
        $inspectData = $network->last_inspect_data ?: [];
        $inspectData['containers'] = data_get($runtime, 'containers', []);
        $inspectData['raw'] = data_get($runtime, 'raw', []);
        $inspectData['proxy_access_runtime'] = $runtimeAccess;
        $inspectData['proxy_access_reconciled'] = $runtimeAccess === (bool) $network->proxy_access;
        $inspectData['proxy_access_checked_at'] = now()->toISOString();

        $network->update([
            'last_inspected_at' => now(),
            'last_inspect_data' => $inspectData,
        ]);
    }

    public function activeDependency(DockerNetwork $network): ?string
    {
        $destinationIds = StandaloneDocker::query()
            ->where('server_id', $network->server_id)
            ->where('network', $network->docker_network_name)
            ->pluck('id');

        $application = $network->server->applications()
            ->whereIn('destination_id', $destinationIds)
            ->whereNotNull('fqdn')
            ->first(fn ($application) => $application->isRunning());

        if ($application) {
            return $application->name;
        }

        $service = $network->server->services()
            ->whereIn('destination_id', $destinationIds)
            ->get()
            ->first(fn ($service) => $service->isRunning() && $service->applications()->whereNotNull('fqdn')->exists());

        if ($service) {
            return $service->name;
        }

        $attachment = NetworkAttachment::query()
            ->where('docker_network_id', $network->id)
            ->where('is_managed', true)
            ->where('is_required', true)
            ->first();

        if (! $attachment) {
            return null;
        }

        return $attachment->attachable?->name ?? 'configured required network attachment';
    }
}
