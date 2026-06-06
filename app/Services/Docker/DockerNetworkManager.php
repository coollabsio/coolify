<?php

namespace App\Services\Docker;

use App\Enums\DockerNetworkRole;
use App\Enums\DockerNetworkSourceType;
use App\Enums\NetworkAttachmentStatus;
use App\Exceptions\DockerNetworkCreationException;
use App\Exceptions\DockerNetworkDeletionException;
use App\Exceptions\DockerNetworkImportException;
use App\Exceptions\DockerNetworkValidationException;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use App\Support\Networking\Cidr;
use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DockerNetworkManager
{
    use ExecutesDockerCommands;

    public function __construct(
        private readonly DockerNetworkInspector $normalizer = new DockerNetworkInspector,
        private readonly DockerNetworkClassifier $classifier = new DockerNetworkClassifier,
        private readonly ?Closure $executor = null,
    ) {}

    public function create(Server $server, array $data): DockerNetwork
    {
        $this->validateCreateData($server, $data);

        $networkName = trim((string) data_get($data, 'docker_network_name'));
        $command = $this->createCommand($networkName, $data);

        if ($this->executeDocker($server, [$command], $this->executor) === null) {
            throw new DockerNetworkCreationException('Failed to create Docker network.');
        }

        $network = null;

        try {
            $normalized = $this->inspectRuntime($server, $networkName);

            if ($normalized === []) {
                throw new DockerNetworkCreationException('Created Docker network could not be inspected.');
            }

            $network = DB::transaction(function () use ($server, $data, $networkName, $normalized) {
                return DockerNetwork::create(array_merge($this->normalizer->toPersistenceArray($normalized), [
                    'server_id' => $server->id,
                    'display_name' => trim($data['display_name']),
                    'docker_network_name' => $networkName,
                    'managed_by_coolify' => true,
                    'external' => false,
                    'is_system' => false,
                    'is_active' => true,
                    'proxy_access' => false,
                    'source_type' => DockerNetworkSourceType::ManagedCustom->value,
                    'source_id' => null,
                    'network_role' => data_get($data, 'internal', false)
                        ? DockerNetworkRole::PrivateInternal->value
                        : DockerNetworkRole::ManagedCustom->value,
                ]));
            });

            if (! (bool) data_get($data, 'internal', false) && (bool) data_get($data, 'proxy_access', false)) {
                $network = $this->proxyReconciler()->enable($network);
            } else {
                $network = $this->proxyReconciler()->detectDrift($network);
            }

            return $network;
        } catch (\Throwable $e) {
            $this->cleanupCreatedNetwork($server, $networkName);
            $network?->delete();

            if ($e instanceof DockerNetworkCreationException) {
                throw $e;
            }

            throw new DockerNetworkCreationException($e->getMessage(), previous: $e);
        }
    }

    public function inspect(DockerNetwork $network): array
    {
        $normalized = $this->inspectRuntime($network->server, $network->docker_network_name);

        if ($normalized === []) {
            $network->update([
                'is_active' => false,
                'last_inspected_at' => now(),
            ]);

            return [];
        }

        $network->update($this->normalizer->toPersistenceArray($normalized) + ['is_active' => true]);

        return $normalized;
    }

    public function sync(DockerNetwork $network): DockerNetwork
    {
        $this->inspect($network);

        return $network->refresh();
    }

    public function import(Server $server, string $networkName, ?string $displayName = null): DockerNetwork
    {
        if (! ValidationPatterns::isValidDockerNetwork($networkName)) {
            throw new DockerNetworkImportException('Invalid Docker network name.');
        }

        $normalized = $this->inspectRuntime($server, $networkName);

        if ($normalized === []) {
            throw new DockerNetworkImportException('Docker network could not be inspected.');
        }

        $runtimeNetworkName = data_get($normalized, 'docker_network_name', $networkName);
        $classification = $this->classifier->classify($server, $runtimeNetworkName);

        if ($classification['is_system']) {
            throw new DockerNetworkImportException('System Docker networks cannot be imported.');
        }

        $dockerNetwork = DockerNetwork::byServer($server)
            ->where(function ($query) use ($networkName, $runtimeNetworkName) {
                $query->where('docker_network_name', $networkName)
                    ->orWhere('docker_network_name', $runtimeNetworkName);
            })
            ->first();

        $attributes = array_merge($this->normalizer->toPersistenceArray($normalized), [
            'source_type' => DockerNetworkSourceType::ImportedExternal->value,
            'source_id' => $classification['source_id'],
            'network_role' => DockerNetworkRole::ManagedCustom->value,
            'is_system' => false,
            'is_active' => true,
            'managed_by_coolify' => false,
            'external' => true,
        ]);

        if ($dockerNetwork) {
            if (filled($displayName)) {
                $attributes['display_name'] = trim($displayName);
            }

            $dockerNetwork->fill($attributes);
            $dockerNetwork->save();

            return $dockerNetwork->refresh();
        }

        return DockerNetwork::create(array_merge($attributes, [
            'server_id' => $server->id,
            'display_name' => trim($displayName ?: $runtimeNetworkName),
            'docker_network_name' => $runtimeNetworkName,
        ]));
    }

    public function renameDisplayName(DockerNetwork $network, string $displayName): DockerNetwork
    {
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new DockerNetworkValidationException('Display name is required and must be at most 255 characters.');
        }

        $network->update(['display_name' => $displayName]);

        return $network->refresh();
    }

    public function updateProxyAccess(DockerNetwork $network, bool $proxyAccess): DockerNetwork
    {
        if ($network->is_system || ! $network->is_active) {
            throw new DockerNetworkValidationException('System or inactive networks cannot be used as deployment defaults.');
        }

        $network = $proxyAccess
            ? $this->proxyReconciler()->enable($network)
            : $this->proxyReconciler()->disable($network);

        return $network->refresh();
    }

    private function proxyReconciler(): ProxyNetworkReconciler
    {
        return new ProxyNetworkReconciler($this->normalizer, $this->executor);
    }

    public function delete(DockerNetwork $network): void
    {
        $this->deleteValidated($network);
    }

    public function deleteWithDestination(DockerNetwork $network): void
    {
        if (! $this->hasDestination($network)) {
            $this->delete($network);

            return;
        }

        $this->deleteValidated($network, allowDestinationRemoval: true);
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function canDeleteWithDestination(DockerNetwork $network): array
    {
        return $this->canDelete($network, allowDestinationRemoval: true);
    }

    private function deleteValidated(DockerNetwork $network, bool $allowDestinationRemoval = false): void
    {
        $deleteCheck = $this->canDelete($network, $allowDestinationRemoval);

        if (! $deleteCheck['allowed']) {
            throw new DockerNetworkDeletionException($deleteCheck['message']);
        }

        $this->refreshNetworkMetadata($network);
        $network->refresh();
        $deleteCheck = $this->canDelete($network, $allowDestinationRemoval);

        if (! $deleteCheck['allowed']) {
            throw new DockerNetworkDeletionException($deleteCheck['message']);
        }

        if ($deleteCheck['proxy_disconnect_required']) {
            $this->proxyReconciler()->disable($network);
            $network->refresh();

            $deleteCheck = $this->canDelete($network, $allowDestinationRemoval);

            if (! $deleteCheck['allowed']) {
                throw new DockerNetworkDeletionException($deleteCheck['message']);
            }
        }

        if ($this->executeDocker($network->server, ['docker network rm -f '.escapeshellarg($network->docker_network_name)], $this->executor) === null) {
            throw new DockerNetworkDeletionException('Failed to remove Docker network.');
        }

        if ($this->inspectRuntime($network->server, $network->docker_network_name) !== []) {
            throw new DockerNetworkDeletionException('Docker network still exists after deletion attempt.');
        }

        $network->delete();
    }

    /**
     * @return array{
     *     allowed: bool,
     *     reason_code: string|null,
     *     message: string,
     *     container_count: int,
     *     containers: array,
     *     resources: array,
     *     blocking_dependencies: array,
     *     proxy_disconnect_required: bool
     * }
     */
    public function canDelete(DockerNetwork $network, bool $allowDestinationRemoval = false): array
    {
        $containers = collect(data_get($network->last_inspect_data, 'containers', []))
            ->map(fn (array $container, string|int $id): array => [
                'id' => (string) $id,
                'name' => ltrim((string) data_get($container, 'Name', $id), '/'),
            ])
            ->values();
        $attachments = NetworkAttachment::query()
            ->with('attachable')
            ->where('docker_network_id', $network->id)
            ->where('is_managed', true)
            ->whereIn('status', [
                NetworkAttachmentStatus::Desired->value,
                NetworkAttachmentStatus::Attached->value,
            ])
            ->get();
        $resources = $attachments->map(fn (NetworkAttachment $attachment): array => [
            'id' => $attachment->attachable_id,
            'name' => $attachment->attachable?->name ?? 'Unknown resource',
            'type' => class_basename((string) $attachment->attachable_type),
        ])->values();

        if (! $network->is_active) {
            return $this->blockedDeletion('inactive_network', 'Inactive Docker networks cannot be permanently deleted.', $containers, $resources);
        }

        if ($network->is_system || in_array($network->docker_network_name, $this->reservedNetworkNames(), true)) {
            return $this->blockedDeletion('system_network', 'Built-in Docker networks cannot be permanently deleted.', $containers, $resources);
        }

        if ($this->isProtectedRuntimeNetwork($network)) {
            return $this->blockedDeletion('protected_runtime_network', 'This network is required by Coolify infrastructure and cannot be permanently deleted.', $containers, $resources);
        }

        $destination = $this->destination($network);

        if (! $allowDestinationRemoval && $destination) {
            return $this->blockedDeletion('destination_configured', 'Remove this network from Destinations before permanently deleting it.', $containers, $resources);
        }

        if ($destination?->attachedTo()) {
            return $this->blockedDeletion(
                'has_attached_resources',
                'This network cannot be permanently deleted because resources are attached.',
                $containers,
                $resources,
            );
        }

        if ($attachments->isNotEmpty()) {
            return $this->blockedDeletion(
                'has_managed_attachments',
                'This network cannot be permanently deleted because resources are attached.',
                $containers,
                $resources,
                $resources->pluck('name')->all(),
            );
        }

        if ($containers->isNotEmpty()) {
            $onlyProxy = $containers->count() === 1 && $containers->first()['name'] === 'coolify-proxy';

            if ($onlyProxy) {
                $dependency = $this->proxyReconciler()->activeDependency($network);

                if ($dependency === null) {
                    return $this->allowedDeletion($containers, $resources, proxyDisconnectRequired: true);
                }

                return $this->blockedDeletion(
                    'proxy_has_active_routes',
                    "The Coolify proxy cannot be disconnected because active routing depends on this network: {$dependency}.",
                    $containers,
                    $resources,
                    [$dependency],
                );
            }

            $count = $containers->count();

            return $this->blockedDeletion(
                'has_connected_containers',
                "This network cannot be permanently deleted because {$count} container(s) are connected.",
                $containers,
                $resources,
                $containers->pluck('name')->all(),
            );
        }

        return $this->allowedDeletion($containers, $resources);
    }

    public function refreshNetworkMetadata(DockerNetwork $network): void
    {
        $normalized = $this->inspectRuntime($network->server, $network->docker_network_name);

        if ($normalized === []) {
            $network->update([
                'is_active' => false,
                'last_inspected_at' => now(),
            ]);

            return;
        }

        $network->update($this->normalizer->toPersistenceArray($normalized) + ['is_active' => true]);
    }

    public function validateCreateData(Server $server, array $data): void
    {
        $displayName = trim((string) data_get($data, 'display_name', ''));

        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new DockerNetworkValidationException('Display name is required and must be at most 255 characters.');
        }

        $networkName = trim((string) data_get($data, 'docker_network_name', ''));

        if ($networkName === '' || ! ValidationPatterns::isValidDockerNetwork($networkName)) {
            throw new DockerNetworkValidationException('Docker network name is required and must be valid.');
        }

        if (DockerNetwork::byServer($server)->byName($networkName)->exists()) {
            throw new DockerNetworkValidationException('Docker network name already exists on this server.');
        }

        if (StandaloneDocker::query()
            ->where('server_id', $server->id)
            ->where('network', $networkName)
            ->exists()
        ) {
            throw new DockerNetworkValidationException('Docker network name already exists on this server.');
        }

        $driver = data_get($data, 'driver', 'bridge');

        if ($driver !== 'bridge') {
            throw new DockerNetworkValidationException('Driver not supported for managed network creation yet.');
        }

        $subnet = data_get($data, 'subnet');
        $gateway = data_get($data, 'gateway');

        if (filled($subnet) && ! Cidr::isValid($subnet)) {
            throw new DockerNetworkValidationException('Subnet must be a valid IPv4 CIDR.');
        }

        if (filled($gateway)) {
            if (! filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new DockerNetworkValidationException('Gateway must be a valid IPv4 address.');
            }

            if (blank($subnet)) {
                throw new DockerNetworkValidationException('Gateway requires a subnet.');
            }

            if (! Cidr::containsIp($subnet, $gateway)) {
                throw new DockerNetworkValidationException('Gateway must belong to the subnet.');
            }
        }

        if (filled($subnet)) {
            $conflictingNetwork = DockerNetwork::byServer($server)
                ->where('is_active', true)
                ->whereNotNull('subnet')
                ->get()
                ->first(fn (DockerNetwork $network) => Cidr::overlaps($subnet, $network->subnet));

            if ($conflictingNetwork) {
                throw new DockerNetworkValidationException('Subnet conflicts with an active Docker network.');
            }
        }
    }

    private function createCommand(string $networkName, array $data): string
    {
        $command = ['docker network create'];
        $command[] = '--driver '.escapeshellarg(data_get($data, 'driver', 'bridge'));

        if (filled(data_get($data, 'subnet'))) {
            $command[] = '--subnet '.escapeshellarg(data_get($data, 'subnet'));
        }

        if (filled(data_get($data, 'gateway'))) {
            $command[] = '--gateway '.escapeshellarg(data_get($data, 'gateway'));
        }

        if ((bool) data_get($data, 'internal', false)) {
            $command[] = '--internal';
        }

        $command[] = escapeshellarg($networkName);

        return implode(' ', $command);
    }

    private function inspectRuntime(Server $server, string $networkName): array
    {
        $decoded = $this->normalizer->rawInspect($server, $networkName, $this->executor);

        if ($decoded === null) {
            return [];
        }

        return $this->normalizer->normalize($decoded);
    }

    private function hasDestination(DockerNetwork $network): bool
    {
        return $this->destination($network) !== null;
    }

    private function destination(DockerNetwork $network): ?StandaloneDocker
    {
        return StandaloneDocker::query()
            ->where('server_id', $network->server_id)
            ->where('network', $network->docker_network_name)
            ->first();
    }

    private function isProtectedRuntimeNetwork(DockerNetwork $network): bool
    {
        return (bool) data_get($network->last_inspect_data, 'raw.Ingress', false)
            || (bool) data_get($network->last_inspect_data, 'raw.ConfigOnly', false)
            || $network->network_role === DockerNetworkRole::ResourceStack
            || $network->network_role === DockerNetworkRole::PreviewStack
            || $network->source_type === DockerNetworkSourceType::ServiceStackDefault
            || $network->source_type === DockerNetworkSourceType::ComposeStackDefault
            || $network->source_type === DockerNetworkSourceType::PreviewDeployment;
    }

    private function allowedDeletion($containers, $resources, bool $proxyDisconnectRequired = false): array
    {
        return [
            'allowed' => true,
            'reason_code' => null,
            'message' => '',
            'container_count' => $containers->count(),
            'containers' => $containers->all(),
            'resources' => $resources->all(),
            'blocking_dependencies' => [],
            'proxy_disconnect_required' => $proxyDisconnectRequired,
        ];
    }

    private function blockedDeletion(string $code, string $message, $containers, $resources, array $dependencies = []): array
    {
        return [
            'allowed' => false,
            'reason_code' => $code,
            'message' => $message,
            'container_count' => $containers->count(),
            'containers' => $containers->all(),
            'resources' => $resources->all(),
            'blocking_dependencies' => array_values($dependencies),
            'proxy_disconnect_required' => false,
        ];
    }

    private function reservedNetworkNames(): array
    {
        return $this->classifier->reservedNetworkNames();
    }

    private function cleanupCreatedNetwork(Server $server, string $networkName): void
    {
        try {
            $this->executeDocker($server, ['docker network rm '.escapeshellarg($networkName)], $this->executor);
        } catch (\Throwable $e) {
            Log::warning('Unable to cleanup newly-created Docker network after database failure.', [
                'server_id' => $server->id,
                'docker_network_name' => $networkName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
