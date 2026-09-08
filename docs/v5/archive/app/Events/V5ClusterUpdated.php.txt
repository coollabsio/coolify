<?php

namespace App\Events;

use App\Models\V5\Cluster as V5Cluster;
use App\Support\V5\ClusterSerializer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class V5ClusterUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Push the queued broadcast job only after the dispatching database
     * transaction commits, so workers never serialize pre-commit state.
     */
    public bool $afterCommit = true;

    public function __construct(public int $teamId, public int $clusterId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'v5.cluster.updated';
    }

    /**
     * @return array{cluster: array<string, mixed>|null}
     */
    public function broadcastWith(): array
    {
        $cluster = V5Cluster::query()
            ->where('team_id', $this->teamId)
            ->with(['servers' => fn ($query) => $query
                ->with('privateKey')
                ->orderBy('name')])
            ->withCount('servers')
            ->find($this->clusterId);

        return [
            'cluster' => $cluster instanceof V5Cluster ? app(ClusterSerializer::class)->serialize($cluster) : null,
        ];
    }
}
