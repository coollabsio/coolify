<?php

namespace App\Actions\V5\Flux;

use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class ApplyFluxResourceStatusUpdate
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ?Model
    {
        $resourceType = strtolower((string) data_get($payload, 'resource_type', data_get($payload, 'type', '')));

        $containerStatus = $resourceType === 'container' ? $this->upsertContainerStatus($payload) : null;

        if ($this->isCaddyIngressStatusUpdate($payload, $resourceType)) {
            return $this->updateCaddyIngress($payload) ?? $containerStatus;
        }

        if (in_array($resourceType, ['server', 'node', 'host'], true)) {
            return $this->updateServer($payload);
        }

        return $this->updateApplication($payload) ?? $containerStatus;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertContainerStatus(array $payload): ?ContainerStatus
    {
        $status = $this->status($payload);
        $containerId = $this->stringValue($payload, 'container_id') ?? $this->stringValue($payload, 'runtime_container_id');
        $server = $this->findServer($payload);

        if ($status === null || $containerId === null || ! $server instanceof V5Server) {
            return null;
        }

        ContainerStatus::query()->updateOrCreate([
            'server_id' => $server->id,
            'container_id' => $containerId,
        ], [
            'team_id' => $server->team_id,
            'container_name' => $this->stringValue($payload, 'container_name') ?? $this->stringValue($payload, 'name'),
            'image' => $this->stringValue($payload, 'image'),
            'status' => $status,
            'status_message' => $this->statusMessage($payload, 'Container state received from coold.'),
            'last_seen_at' => now(),
        ]);

        return ContainerStatus::query()
            ->where('server_id', $server->id)
            ->where('container_id', $containerId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateApplication(array $payload): ?V5Application
    {
        $status = $this->status($payload);

        if ($status === null) {
            return null;
        }

        $application = $this->findApplication($payload);

        if (! $application instanceof V5Application) {
            return null;
        }

        $application->update([
            'status' => $status,
            'status_message' => $this->statusMessage($payload, 'Status updated by flux.'),
            'runtime_container_id' => $this->stringValue($payload, 'runtime_container_id')
                ?? $this->stringValue($payload, 'container_id')
                ?? $application->runtime_container_id,
        ]);

        return $application->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateServer(array $payload): ?V5Server
    {
        $status = $this->status($payload);

        if ($status === null) {
            return null;
        }

        $server = $this->findServer($payload);

        if (! $server instanceof V5Server) {
            return null;
        }

        $server->update([
            'status' => $status,
            'last_status_check' => 'flux',
            'last_status_output' => $this->statusMessage($payload, 'Status updated by flux.'),
            'last_status_checked_at' => now(),
        ]);

        return $server->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateCaddyIngress(array $payload): ?V5Server
    {
        $status = $this->status($payload);

        if ($status === null) {
            return null;
        }

        $server = $this->findServer($payload);

        if (! $server instanceof V5Server || ! $server->isIngress()) {
            return null;
        }

        $server->update([
            'ingress_type' => 'caddy',
            'ingress_status' => $status,
            'last_status_check' => 'flux',
            'last_status_output' => $this->statusMessage($payload, 'Status updated by flux.'),
            'last_status_checked_at' => now(),
        ]);

        return $server->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findApplication(array $payload): ?V5Application
    {
        $query = V5Application::query()->with('server');
        $teamId = $this->intValue($payload, 'team_id');
        $server = $this->findServer($payload);

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }

        if ($server instanceof V5Server) {
            $query->where('server_id', $server->id);
        }

        $applicationId = $this->intValue($payload, 'application_id') ?? $this->intValue($payload, 'resource_id');

        if ($applicationId !== null) {
            return $query->whereKey($applicationId)->first();
        }

        $containerName = $this->stringValue($payload, 'container_name') ?? $this->stringValue($payload, 'name');

        if ($containerName !== null) {
            return $query->where('container_name', $containerName)->first();
        }

        $containerId = $this->stringValue($payload, 'runtime_container_id') ?? $this->stringValue($payload, 'container_id');

        if ($containerId !== null) {
            return $query->where('runtime_container_id', $containerId)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isCaddyIngressStatusUpdate(array $payload, string $resourceType): bool
    {
        if (in_array($resourceType, ['caddy_ingress', 'caddy-ingress'], true)) {
            return true;
        }

        return $this->stringValue($payload, 'container_name') === 'coolify-v5-caddy'
            || $this->stringValue($payload, 'name') === 'coolify-v5-caddy';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findServer(array $payload): ?V5Server
    {
        $serverId = $this->intValue($payload, 'server_id') ?? $this->intValue($payload, 'host_server_id');

        if ($serverId !== null) {
            return V5Server::query()->find($serverId);
        }

        $hostId = $this->stringValue($payload, 'host_id')
            ?? $this->stringValue($payload, 'node_id')
            ?? $this->stringValue($payload, 'server_host');

        if ($hostId === null) {
            return null;
        }

        return V5Server::query()
            ->where('wireguard_management_ip', $hostId)
            ->orWhere('node_address', $hostId)
            ->orWhere('host', $hostId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function status(array $payload): ?string
    {
        $status = $this->stringValue($payload, 'status') ?? $this->stringValue($payload, 'state');

        return $status === null ? null : strtolower($status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function statusMessage(array $payload, string $fallback): string
    {
        return $this->stringValue($payload, 'status_message')
            ?? $this->stringValue($payload, 'message')
            ?? $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = data_get($payload, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intValue(array $payload, string $key): ?int
    {
        $value = data_get($payload, $key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
