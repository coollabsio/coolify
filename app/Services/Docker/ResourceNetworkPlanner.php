<?php

namespace App\Services\Docker;

use App\Enums\NetworkAttachmentStatus;
use App\Models\NetworkAttachment;
use App\Models\Server;
use App\Services\Docker\Concerns\ExecutesDockerCommands;
use Closure;

class ResourceNetworkPlanner
{
    use ExecutesDockerCommands;

    private readonly DockerNetworkInspector $normalizer;

    public function __construct(
        private readonly NetworkAttachableResolver $resolver = new NetworkAttachableResolver,
        private readonly ?Closure $executor = null,
        ?DockerNetworkInspector $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new DockerNetworkInspector;
    }

    public function disconnect(NetworkAttachment $attachment): array
    {
        if (! $this->canDisconnect($attachment)) {
            return $this->attachmentResult($attachment, 'disconnect', false, 'Only managed, non-runtime-discovered attachments can be disconnected.');
        }

        $network = $attachment->dockerNetwork;

        if (! $network || $network->server_id !== $attachment->server_id || $network->is_system) {
            return $this->mark($attachment, NetworkAttachmentStatus::Failed, 'Network cannot be disconnected safely.', 'disconnect', false);
        }

        $resolved = $this->resolveContainer($attachment);

        if (! $resolved) {
            return $this->mark($attachment, NetworkAttachmentStatus::MissingContainer, 'Could not find the running container for this resource.', 'disconnect', false);
        }

        $inspect = $this->inspectContainer($attachment->server, $resolved['name']);

        if ($inspect === []) {
            return $this->mark($attachment, NetworkAttachmentStatus::MissingContainer, 'Could not find the running container for this resource.', 'disconnect', false);
        }

        if (! $this->isContainerAttachedToNetwork($inspect, $network->docker_network_name)) {
            return $this->mark($attachment, NetworkAttachmentStatus::Detached, null, 'skip', true, 'Already detached.', $resolved, $network->docker_network_name);
        }

        $command = 'docker network disconnect '.escapeshellarg($network->docker_network_name).' '.escapeshellarg($resolved['name']);
        $output = $this->executeDocker($attachment->server, [$command], $this->executor);

        if ($output === null) {
            return $this->mark($attachment, NetworkAttachmentStatus::Failed, 'Docker network disconnect failed.', 'disconnect', false, null, $resolved, $network->docker_network_name);
        }

        $postDisconnectInspect = $this->inspectContainer($attachment->server, $resolved['name']);

        if ($this->isContainerAttachedToNetwork($postDisconnectInspect, $network->docker_network_name)) {
            return $this->mark($attachment, NetworkAttachmentStatus::Failed, 'Docker network disconnect could not be verified.', 'disconnect', false, null, $resolved, $network->docker_network_name);
        }

        return $this->mark($attachment, NetworkAttachmentStatus::Detached, null, 'disconnect', true, 'Detached from runtime.', $resolved, $network->docker_network_name);
    }

    public function connect(NetworkAttachment $attachment): array
    {
        $network = $attachment->dockerNetwork;

        if (! $attachment->is_managed || $attachment->is_runtime_discovered) {
            return $this->attachmentResult($attachment, 'error', false, 'Attachment is not managed by the desired networking system.');
        }

        if (! $network || $network->server_id !== $attachment->server_id || ! $network->is_active) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::MissingNetwork,
                'last_checked_at' => now(),
                'last_error' => 'The selected network no longer exists on this server.',
            ]);

            return $this->attachmentResult($attachment, 'error', false, 'The selected network no longer exists on this server.');
        }

        $resolved = $this->resolveContainer($attachment);

        if (! $resolved) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::MissingContainer,
                'last_checked_at' => now(),
                'last_error' => 'Could not find the running container for this resource.',
                'container_id' => null,
                'container_name' => null,
            ]);

            return $this->attachmentResult($attachment, 'error', false, 'Could not find the running container for this resource.');
        }

        if (! $this->networkExists($attachment->server, $network->docker_network_name)) {
            $network->update(['is_active' => false, 'last_inspected_at' => now()]);
            $attachment->update([
                'status' => NetworkAttachmentStatus::MissingNetwork,
                'last_checked_at' => now(),
                'last_error' => 'The selected network no longer exists on this server.',
            ]);

            return $this->attachmentResult($attachment, 'error', false, 'The selected network no longer exists on this server.');
        }

        $inspect = $this->inspectContainer($attachment->server, $resolved['name']);

        if ($inspect === []) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::MissingContainer,
                'last_checked_at' => now(),
                'last_error' => 'Could not find the running container for this resource.',
                'container_id' => null,
            ]);

            return $this->attachmentResult($attachment, 'error', false, 'Could not find the running container for this resource.', $resolved['name']);
        }

        $resolved['id'] = data_get($inspect, 'Id', $resolved['id']);
        $attachment->update([
            'container_id' => $resolved['id'],
            'container_name' => $resolved['name'],
        ]);

        if ($this->isContainerAttachedToNetwork($inspect, $network->docker_network_name)) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::Attached,
                'last_checked_at' => now(),
                'last_error' => null,
                'container_id' => $resolved['id'],
                'container_name' => $resolved['name'],
            ]);

            return $this->attachmentResult($attachment, 'skip', true, 'Already attached.', $resolved['name'], $network->docker_network_name);
        }

        $command = $this->connectCommand($network->docker_network_name, $resolved['name'], $attachment->aliases ?? []);
        $output = $this->executeDocker($attachment->server, [$command], $this->executor);

        if ($output === null) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::Failed,
                'last_checked_at' => now(),
                'last_error' => 'Could not connect this resource to the selected network.',
            ]);

            return $this->attachmentResult($attachment, 'connect', false, 'Could not connect this resource to the selected network.', $resolved['name'], $network->docker_network_name);
        }

        $postConnectInspect = $this->inspectContainer($attachment->server, $resolved['name']);

        if (! $this->isContainerAttachedToNetwork($postConnectInspect, $network->docker_network_name)) {
            $attachment->update([
                'status' => NetworkAttachmentStatus::Failed,
                'last_checked_at' => now(),
                'last_error' => 'Could not connect this resource to the selected network.',
            ]);

            return $this->attachmentResult($attachment, 'connect', false, 'Could not connect this resource to the selected network.', $resolved['name'], $network->docker_network_name);
        }

        $resolved['id'] = data_get($postConnectInspect, 'Id', $resolved['id']);

        $attachment->update([
            'status' => NetworkAttachmentStatus::Attached,
            'last_checked_at' => now(),
            'last_error' => null,
            'container_id' => $resolved['id'],
            'container_name' => $resolved['name'],
        ]);

        return $this->attachmentResult($attachment, 'connect', true, 'Attached to runtime network.', $resolved['name'], $network->docker_network_name);
    }

    public function resolveContainer(NetworkAttachment $attachment): ?array
    {
        return $this->resolver->resolveRuntimeContainer($attachment->attachable, $attachment);
    }

    public function inspectContainer(Server $server, string $container): array
    {
        $output = $this->executeDocker($server, ['docker inspect '.escapeshellarg($container)], $this->executor);
        $decoded = json_decode((string) $output, true);

        if ($output === null || ! is_array($decoded)) {
            return [];
        }

        return $this->normalizer->firstNetwork($decoded);
    }

    public function isContainerAttachedToNetwork(array $containerInspect, string $networkName): bool
    {
        $networks = data_get($containerInspect, 'NetworkSettings.Networks', []);

        return is_array($networks) && array_key_exists($networkName, $networks);
    }

    private function networkExists(Server $server, string $networkName): bool
    {
        return $this->normalizer->rawInspect($server, $networkName, $this->executor) !== null;
    }

    private function connectCommand(string $networkName, string $containerName, array $aliases): string
    {
        $command = ['docker network connect'];

        foreach ($aliases as $alias) {
            if (filled($alias)) {
                $command[] = '--alias '.escapeshellarg($alias);
            }
        }

        $command[] = escapeshellarg($networkName);
        $command[] = escapeshellarg($containerName);

        return implode(' ', $command);
    }

    private function canDisconnect(NetworkAttachment $attachment): bool
    {
        return $attachment->is_managed && ! $attachment->is_runtime_discovered;
    }

    private function mark(
        NetworkAttachment $attachment,
        NetworkAttachmentStatus $status,
        ?string $error,
        string $action,
        bool $success,
        ?string $message = null,
        ?array $resolved = null,
        ?string $networkName = null,
    ): array {
        $attachment->update([
            'status' => $status,
            'last_checked_at' => now(),
            'last_error' => $error,
            'container_id' => data_get($resolved, 'id', $attachment->container_id),
            'container_name' => data_get($resolved, 'name', $attachment->container_name),
        ]);

        return $this->attachmentResult(
            $attachment,
            $action,
            $success,
            $message ?: $error ?: $status->value,
            data_get($resolved, 'name'),
            $networkName,
        );
    }

    private function attachmentResult(
        NetworkAttachment $attachment,
        string $action,
        bool $success,
        string $message,
        ?string $containerName = null,
        ?string $networkName = null,
    ): array {
        return [
            'attachment_id' => $attachment->id,
            'success' => $success,
            'message' => $message,
            'action' => $action,
            'container_name' => $containerName ?: $attachment->container_name,
            'docker_network_name' => $networkName ?: $attachment->dockerNetwork?->docker_network_name,
        ];
    }
}
