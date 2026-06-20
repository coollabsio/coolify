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
     * @return array{application: array<string, mixed>|null, caddyIngress: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        $application = $this->applicationId !== null
            ? V5Application::query()->with('server')->find($this->applicationId)
            : null;
        $caddyIngress = $this->caddyIngressServerId !== null
            ? V5Server::query()->find($this->caddyIngressServerId)
            : null;

        return [
            'application' => $application instanceof V5Application ? $this->serializeApplication($application) : null,
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
        return [
            'id' => (string) $application->id,
            'name' => $application->name,
            'image' => $application->image,
            'containerName' => $application->container_name,
            'status' => $application->status,
            'statusMessage' => $application->status_message,
            'runtimeContainerId' => $application->runtime_container_id,
            'serverName' => $application->server?->name,
            'meshNamespace' => $application->mesh_namespace,
            'meshFqdn' => $application->container_name.'.'.($application->mesh_namespace ?: 'default').'.coolify.internal',
            'canvasX' => $application->canvas_x,
            'canvasY' => $application->canvas_y,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCaddyIngress(V5Server $server): array
    {
        return [
            'id' => (string) $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'type' => $server->ingressType(),
            'status' => $server->ingressStatus(),
            'canvasX' => $server->canvas_x ?? -352,
            'canvasY' => $server->canvas_y ?? 0,
        ];
    }
}
