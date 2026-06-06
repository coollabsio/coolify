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

        $networkName = $this->generateDockerNetworkName($server);
        $command = $this->createCommand($networkName, $data);

        if ($this->executeDocker($server, [$command], $this->executor) === null) {
            throw new DockerNetworkCreationException('Failed to create Docker network.');
        }

        try {
            $normalized = $this->inspectRuntime($server, $networkName);

            if ($normalized === []) {
                throw new DockerNetworkCreationException('Created Docker network could not be inspected.');
            }

            return DB::transaction(function () use ($server, $data, $networkName, $normalized) {
                return DockerNetwork::create(array_merge($this->normalizer->toPersistenceArray($normalized), [
                    'server_id' => $server->id,
                    'display_name' => trim($data['display_name']),
                    'docker_network_name' => $networkName,
                    'managed_by_coolify' => true,
                    'external' => false,
                    'is_system' => false,
                    'is_active' => true,
                    'source_type' => DockerNetworkSourceType::ManagedCustom->value,
                    'source_id' => null,
                    'network_role' => data_get($data, 'internal', false)
                        ? DockerNetworkRole::PrivateInternal->value
                        : DockerNetworkRole::ManagedCustom->value,
                ]));
            });
        } catch (\Throwable $e) {
            $this->cleanupCreatedNetwork($server, $networkName);

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
            'managed_by_coolify' => true,
            'external' => false,
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

    public function delete(DockerNetwork $network): void
    {
        $deleteCheck = $this->canDelete($network);

        if (! $deleteCheck['allowed']) {
            throw new DockerNetworkDeletionException($deleteCheck['reason']);
        }

        $this->refreshNetworkMetadata($network);
        $network->refresh();
        $deleteCheck = $this->canDelete($network);

        if (! $deleteCheck['allowed']) {
            throw new DockerNetworkDeletionException($deleteCheck['reason']);
        }

        if ($this->executeDocker($network->server, ['docker network rm '.escapeshellarg($network->docker_network_name)], $this->executor) === null) {
            throw new DockerNetworkDeletionException('Failed to remove Docker network.');
        }

        $inspectData = $network->last_inspect_data ?: [];
        $inspectData['deleted_at'] = now()->toISOString();

        $network->update([
            'is_active' => false,
            'last_inspected_at' => now(),
            'last_inspect_data' => $inspectData,
        ]);
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function canDelete(DockerNetwork $network): array
    {
        if ($network->is_system) {
            return ['allowed' => false, 'reason' => 'system_network'];
        }

        if ($network->external) {
            return ['allowed' => false, 'reason' => 'external_network'];
        }

        if (! $network->managed_by_coolify) {
            return ['allowed' => false, 'reason' => 'not_managed_by_coolify'];
        }

        if (! $network->is_active) {
            return ['allowed' => false, 'reason' => 'inactive_network'];
        }

        if ($this->hasManagedActiveAttachments($network)) {
            return ['allowed' => false, 'reason' => 'has_managed_attachments'];
        }

        $containers = data_get($network->last_inspect_data, 'containers', []);

        if (is_array($containers) && count($containers) > 0) {
            return ['allowed' => false, 'reason' => 'has_connected_containers'];
        }

        return ['allowed' => true, 'reason' => null];
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

    private function generateDockerNetworkName(Server $server): string
    {
        return generateUniqueDockerNetworkName($server);
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

        if ((bool) data_get($data, 'attachable', true)) {
            $command[] = '--attachable';
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

    private function hasManagedActiveAttachments(DockerNetwork $network): bool
    {
        return NetworkAttachment::query()
            ->where('docker_network_id', $network->id)
            ->where('is_managed', true)
            ->whereIn('status', [
                NetworkAttachmentStatus::Desired->value,
                NetworkAttachmentStatus::Attached->value,
            ])
            ->exists();
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
