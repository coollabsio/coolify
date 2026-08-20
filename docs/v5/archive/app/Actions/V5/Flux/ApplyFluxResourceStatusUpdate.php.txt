<?php

namespace App\Actions\V5\Flux;

use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\ContainerState;
use App\Enums\V5\IngressStatus;
use App\Enums\V5\ServerStatus;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ContainerStatus;
use App\Models\V5\Server as V5Server;
use App\Support\V5\StatusObservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        $status = $this->status($payload, ContainerState::class);
        $containerId = $this->stringValue($payload, 'container_id') ?? $this->stringValue($payload, 'runtime_container_id');
        $server = $this->findServer($payload);

        if ($status === null || $containerId === null || ! $server instanceof V5Server) {
            return null;
        }

        $observedAt = $this->observedAt($payload);
        $existing = ContainerStatus::query()
            ->where('server_id', $server->id)
            ->where('container_id', $containerId)
            ->first();

        if ($this->isStaleObservation($observedAt, $existing?->status_observed_at, 'container status', [
            'server_id' => $server->id,
            'container_id' => $containerId,
        ])) {
            return $existing;
        }

        $attributes = [
            'team_id' => $server->team_id,
            'container_name' => $this->stringValue($payload, 'container_name') ?? $this->stringValue($payload, 'name'),
            'image' => $this->stringValue($payload, 'image'),
            'status' => $status,
            'status_message' => $this->statusMessage($payload, 'Container state received from coold.'),
            'last_seen_at' => now(),
        ];

        if ($observedAt !== null) {
            $attributes['status_observed_at'] = $observedAt;
        }

        ContainerStatus::query()->updateOrCreate([
            'server_id' => $server->id,
            'container_id' => $containerId,
        ], $attributes);

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
        $status = $this->status($payload, ApplicationStatus::class);

        if ($status === null) {
            return null;
        }

        $application = $this->findApplication($payload);

        if (! $application instanceof V5Application) {
            return null;
        }

        $observedAt = $this->observedAt($payload);

        if ($this->isStaleObservation($observedAt, $application->status_observed_at, 'application status', [
            'application_id' => $application->id,
        ])) {
            return $application;
        }

        $payloadContainerId = $this->stringValue($payload, 'runtime_container_id')
            ?? $this->stringValue($payload, 'container_id');

        // Payloads may carry no timestamp, so the container id remains an
        // ordering signal as a second layer: an update for a superseded
        // container is stale and must not overwrite the current one's state.
        if (
            $payloadContainerId !== null
            && $application->runtime_container_id !== null
            && $payloadContainerId !== $application->runtime_container_id
        ) {
            return $application;
        }

        $attributes = [
            'status' => $status,
            'status_message' => $this->statusMessage($payload, 'Status updated by flux.'),
            'runtime_container_id' => $payloadContainerId ?? $application->runtime_container_id,
        ];

        if ($observedAt !== null) {
            $attributes['status_observed_at'] = $observedAt;
        }

        $application->update($attributes);

        return $application->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateServer(array $payload): ?V5Server
    {
        $status = $this->status($payload, ServerStatus::class);

        if ($status === null) {
            return null;
        }

        $server = $this->findServer($payload);

        if (! $server instanceof V5Server) {
            return null;
        }

        $observedAt = $this->observedAt($payload);

        if ($this->isStaleObservation($observedAt, $server->status_observed_at, 'server status', [
            'server_id' => $server->id,
        ])) {
            return $server;
        }

        $attributes = [
            'status' => $status,
            'last_status_check' => 'flux',
            'last_status_output' => $this->statusMessage($payload, 'Status updated by flux.'),
            'last_status_checked_at' => now(),
        ];

        if ($observedAt !== null) {
            $attributes['status_observed_at'] = $observedAt;
        }

        $server->update($attributes);

        return $server->refresh();
    }

    /**
     * The ingress state shares the server row but describes a different
     * resource, so it deliberately does not read or write the server's
     * `status_observed_at` watermark.
     *
     * @param  array<string, mixed>  $payload
     */
    private function updateCaddyIngress(array $payload): ?V5Server
    {
        $status = $this->status($payload, IngressStatus::class);

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
        $server = $this->findServer($payload);

        if (! $server instanceof V5Server) {
            return null;
        }

        $query = V5Application::query()
            ->with('server')
            ->where('server_id', $server->id)
            ->where('team_id', $server->team_id);

        $applicationUuid = $this->stringValue($payload, 'application_uuid') ?? $this->stringValue($payload, 'resource_uuid');

        if ($applicationUuid !== null) {
            return $query->where('uuid', $applicationUuid)->first();
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
        $serverUuid = $this->stringValue($payload, 'server_uuid') ?? $this->stringValue($payload, 'host_server_uuid');

        if ($serverUuid !== null) {
            return V5Server::query()->where('uuid', $serverUuid)->first();
        }

        $hostId = $this->stringValue($payload, 'host_id')
            ?? $this->stringValue($payload, 'node_id')
            ?? $this->stringValue($payload, 'server_host');

        if ($hostId === null) {
            return null;
        }

        $matches = V5Server::query()
            ->where('uuid', $hostId)
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            Log::warning('Dropping flux resource status update: host id matches multiple v5 servers.', [
                'host_id' => $hostId,
                'server_ids' => $matches->pluck('id')->all(),
            ]);

            return null;
        }

        return $matches->first();
    }

    /**
     * Map the raw payload status onto the given status enum. Unknown values
     * are never written to the database: they fall back to the enum's
     * Unknown case and are logged.
     *
     * @param  array<string, mixed>  $payload
     * @param  class-string<ApplicationStatus|ContainerState|IngressStatus|ServerStatus>  $enumClass
     */
    private function status(array $payload, string $enumClass): ?string
    {
        $raw = $this->stringValue($payload, 'status') ?? $this->stringValue($payload, 'state');

        return StatusObservation::normalize($raw, $enumClass);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function observedAt(array $payload): ?CarbonInterface
    {
        $observedAt = $this->stringValue($payload, 'observed_at');

        if ($observedAt === null) {
            return null;
        }

        return rescue(fn (): CarbonImmutable => CarbonImmutable::parse($observedAt), null, false);
    }

    /**
     * A payload that carries an observation timestamp older than the one
     * already persisted is stale (delivered out of order) and must not
     * clobber the newer state.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function isStaleObservation(?CarbonInterface $observedAt, ?CarbonInterface $currentObservedAt, string $context, array $logContext): bool
    {
        return StatusObservation::isStale($observedAt, $currentObservedAt, $context, $logContext);
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
}
