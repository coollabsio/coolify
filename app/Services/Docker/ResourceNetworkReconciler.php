<?php

namespace App\Services\Docker;

use App\Enums\NetworkAttachmentStatus;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResourceNetworkReconciler
{
    use ExecutesDockerCommands;

    public function __construct(
        private readonly NetworkAttachableResolver $resolver = new NetworkAttachableResolver,
        private readonly DockerNetworkInspector $inspector = new DockerNetworkInspector,
        private readonly DockerNetworkClassifier $classifier = new DockerNetworkClassifier,
        private readonly ?Closure $executor = null,
    ) {}

    public function reconcile(Model $resource): Collection
    {
        $server = $this->resolver->resolveServer($resource);

        if (! $server) {
            return $this->attachmentsForResource($resource);
        }

        $resolvedContainer = $this->resolver->resolveRuntimeContainer($resource);

        if (! $resolvedContainer) {
            return $this->attachmentsForResource($resource);
        }

        $containerInspect = $this->inspectContainer($server, $resolvedContainer['name']);

        if ($containerInspect === []) {
            return $this->attachmentsForResource($resource);
        }

        DB::transaction(function () use ($resource, $server, $resolvedContainer, $containerInspect): void {
            $runtimeNetworks = collect(data_get($containerInspect, 'NetworkSettings.Networks', []))
                ->filter(fn ($details, $networkName): bool => is_string($networkName) && is_array($details))
                ->mapWithKeys(fn (array $details, string $networkName): array => [$networkName => $details]);

            $persistedAttachments = $this->attachmentsForResource($resource)
                ->groupBy(fn (NetworkAttachment $attachment): string => (string) $attachment->dockerNetwork?->docker_network_name);

            foreach ($runtimeNetworks as $networkName => $details) {
                $network = $this->ensureDockerNetwork($server, $networkName);

                if (! $network) {
                    continue;
                }

                $payload = [
                    'status' => NetworkAttachmentStatus::Attached,
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'container_id' => $resolvedContainer['id'],
                    'container_name' => $resolvedContainer['name'],
                    'ipv4_address' => data_get($details, 'IPAddress'),
                    'ipv6_address' => data_get($details, 'GlobalIPv6Address'),
                ];

                $attachments = $persistedAttachments->pull($network->docker_network_name, collect());

                if ($attachments->isNotEmpty()) {
                    foreach ($attachments as $attachment) {
                        $attachment->update($payload);
                    }

                    continue;
                }

                // Persist runtime-only memberships so UI and later actions can distinguish config from reality.
                NetworkAttachment::create(array_merge($payload, [
                    'server_id' => $server->id,
                    'docker_network_id' => $network->id,
                    'attachable_type' => $resource::class,
                    'attachable_id' => $resource->id,
                    'resource_type' => $this->resolver->resolveResourceType($resource),
                    'resource_id' => $resource->id,
                    'aliases' => $this->runtimeAliases($details, $resolvedContainer['name']),
                    'is_primary' => false,
                    'is_required' => false,
                    'is_managed' => false,
                    'is_runtime_discovered' => true,
                ]));
            }

            foreach ($persistedAttachments as $attachments) {
                foreach ($attachments as $attachment) {
                    if ($attachment->is_runtime_discovered) {
                        $attachment->delete();

                        continue;
                    }

                    if ($attachment->status === NetworkAttachmentStatus::Attached) {
                        $attachment->update([
                            'status' => NetworkAttachmentStatus::Detached,
                            'last_checked_at' => now(),
                            'last_error' => null,
                            'container_id' => $resolvedContainer['id'],
                            'container_name' => $resolvedContainer['name'],
                            'ipv4_address' => null,
                            'ipv6_address' => null,
                        ]);
                    }
                }
            }
        });

        return $this->attachmentsForResource($resource);
    }

    public function inspectContainer(Server $server, string $containerName): array
    {
        $output = $this->executeDocker($server, ['docker inspect '.escapeshellarg($containerName)], $this->executor);
        $decoded = json_decode((string) $output, true);

        if ($output === null || ! is_array($decoded)) {
            return [];
        }

        return $this->inspector->firstNetwork($decoded);
    }

    private function ensureDockerNetwork(Server $server, string $networkName): ?DockerNetwork
    {
        $dockerNetwork = DockerNetwork::query()
            ->byServer($server)
            ->byName($networkName)
            ->first();

        $normalized = $this->inspectNetwork($server, $networkName);

        if ($normalized === []) {
            return $dockerNetwork;
        }

        $runtimeNetworkName = (string) data_get($normalized, 'docker_network_name', $networkName);
        $classification = $this->classifier->classify($server, $runtimeNetworkName);
        $attributes = array_merge($this->inspector->toPersistenceArray($normalized), [
            'source_type' => $classification['source_type'],
            'source_id' => $classification['source_id'],
            'network_role' => $classification['network_role'],
            'managed_by_coolify' => $classification['managed_by_coolify'],
            'external' => $classification['external'],
            'is_system' => $classification['is_system'],
            'is_active' => true,
        ]);

        if ($dockerNetwork) {
            $dockerNetwork->fill($attributes);
            $dockerNetwork->save();

            return $dockerNetwork->refresh();
        }

        return DockerNetwork::create(array_merge($attributes, [
            'server_id' => $server->id,
            'display_name' => $runtimeNetworkName,
            'docker_network_name' => $runtimeNetworkName,
        ]));
    }

    private function inspectNetwork(Server $server, string $networkName): array
    {
        $decoded = $this->inspector->rawInspect($server, $networkName, $this->executor);

        if ($decoded === null) {
            return [];
        }

        return $this->inspector->normalize($decoded);
    }

    private function attachmentsForResource(Model $resource): Collection
    {
        return NetworkAttachment::query()
            ->with('dockerNetwork')
            ->where('attachable_type', $resource::class)
            ->where('attachable_id', $resource->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('is_managed')
            ->orderBy('id')
            ->get();
    }

    private function runtimeAliases(array $details, string $containerName): array
    {
        return collect(data_get($details, 'Aliases', []))
            ->filter(fn ($alias): bool => is_string($alias) && $alias !== '' && $alias !== $containerName)
            ->unique()
            ->values()
            ->all();
    }
}
