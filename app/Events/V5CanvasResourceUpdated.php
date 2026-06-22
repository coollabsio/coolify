<?php

namespace App\Events;

use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class V5CanvasResourceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $teamId,
        public ?int $applicationId = null,
        public ?int $caddyIngressServerId = null,
        public ?int $serverId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'v5.canvas.resource.updated';
    }

    /**
     * @return array{application: array<string, mixed>|null, applications: array<int, array<string, mixed>>, caddyIngress: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        $application = $this->applicationId !== null
            ? V5Application::query()->with(['server', 'domains'])->find($this->applicationId)
            : null;
        $applications = $this->serverId !== null
            ? V5Application::query()
                ->where('server_id', $this->serverId)
                ->with(['server', 'domains'])
                ->get()
            : collect();
        $caddyIngress = $this->caddyIngressServerId !== null
            ? V5Server::query()->find($this->caddyIngressServerId)
            : null;

        return [
            'application' => $application instanceof V5Application ? $this->serializeApplication($application) : null,
            'applications' => $applications
                ->map(fn (V5Application $application) => $this->serializeApplication($application))
                ->values()
                ->all(),
            'caddyIngress' => $caddyIngress instanceof V5Server && $caddyIngress->isIngress()
                ? $this->serializeCaddyIngress($caddyIngress)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApplication(V5Application $application): array
    {
        $server = $application->server;
        $isServerReachable = ! $server instanceof V5Server || $this->isServerReachable($server);

        return [
            'id' => (string) $application->id,
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
            'canvasX' => $application->canvas_x,
            'canvasY' => $application->canvas_y,
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

    /**
     * @return array<string, mixed>
     */
    private function serializeCaddyIngress(V5Server $server): array
    {
        $isServerReachable = $this->isServerReachable($server);

        return [
            'id' => (string) $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'type' => $server->ingressType(),
            'status' => $isServerReachable ? $server->ingressStatus() : 'unreachable',
            'statusMessage' => $isServerReachable ? null : $this->serverStatusMessage($server),
            'canvasX' => $server->canvas_x ?? -352,
            'canvasY' => $server->canvas_y ?? 0,
        ];
    }
}
