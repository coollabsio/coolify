<?php

namespace App\Support\V5;

use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;

/**
 * Single source of truth for the canvas resource payloads served by the
 * dashboard Inertia props and broadcast by V5CanvasResourceUpdated — the two
 * must stay identical for websocket vs. initial-load parity.
 */
class CanvasResourceSerializer
{
    public const CARD_WIDTH = 320;

    public const CARD_HEIGHT = 144;

    public const CARD_GAP = 32;

    /**
     * @return array<string, mixed>
     */
    public function serializeApplication(V5Application $application): array
    {
        $application->loadMissing(['server', 'domains', 'project', 'environment']);
        $server = $application->server;
        $isServerReachable = ! $server instanceof V5Server || $this->isServerReachable($server);

        return [
            'id' => $application->uuid,
            'name' => $application->name,
            'image' => $application->image,
            'containerName' => $application->container_name,
            'status' => $application->status,
            'statusMessage' => $application->status_message,
            'effectiveStatus' => $isServerReachable ? $application->status : 'unknown',
            'effectiveStatusMessage' => $isServerReachable
                ? $application->status_message
                : $this->serverStatusMessage($server),
            'runtimeContainerId' => $application->runtime_container_id,
            'serverName' => $server?->name,
            'serverStatus' => $server?->status,
            'serverStatusMessage' => $server instanceof V5Server ? $this->serverStatusMessage($server) : null,
            'isServerReachable' => $isServerReachable,
            'serverIngressEnabled' => (bool) $server?->isIngress(),
            'meshNamespace' => $application->mesh_namespace,
            'ingressEnabled' => $application->ingress_enabled,
            'internalPort' => $application->internal_port,
            'domains' => $application->domains->pluck('domain')->values()->all(),
            'meshFqdn' => $application->container_name.'.'.($application->mesh_namespace ?: 'default').'.coolify.internal',
            'projectUuid' => $application->project?->uuid,
            'environmentUuid' => $application->environment?->uuid,
            'canvasX' => $application->canvas_x,
            'canvasY' => $application->canvas_y,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCaddyIngress(V5Server $server, int $index = 0): array
    {
        $isServerReachable = $this->isServerReachable($server);

        return [
            'id' => $server->uuid,
            'name' => $server->name,
            'host' => $server->host,
            'type' => $server->ingressType(),
            'status' => $isServerReachable ? $server->ingressStatus() : 'unreachable',
            'statusMessage' => $isServerReachable ? null : $this->serverStatusMessage($server),
            'canvasX' => $server->canvas_x ?? -(self::CARD_WIDTH + self::CARD_GAP),
            'canvasY' => $server->canvas_y ?? $index * (self::CARD_HEIGHT + self::CARD_GAP),
        ];
    }

    private function isServerReachable(V5Server $server): bool
    {
        return $server->status !== 'unreachable';
    }

    private function serverStatusMessage(?V5Server $server): ?string
    {
        return $server?->last_status_output ?: null;
    }
}
