<?php

namespace App\Events;

use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use App\Support\V5\CanvasResourceSerializer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class V5CanvasResourceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Push the queued broadcast job only after the dispatching database
     * transaction commits, so workers never serialize pre-commit state.
     */
    public bool $afterCommit = true;

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
        $serializer = app(CanvasResourceSerializer::class);
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
            'application' => $application instanceof V5Application ? $serializer->serializeApplication($application) : null,
            'applications' => $applications
                ->map(fn (V5Application $application) => $serializer->serializeApplication($application))
                ->values()
                ->all(),
            'caddyIngress' => $caddyIngress instanceof V5Server && $caddyIngress->isIngress()
                ? $serializer->serializeCaddyIngress($caddyIngress)
                : null,
        ];
    }
}
