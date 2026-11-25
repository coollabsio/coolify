<?php

namespace App\Events;

use App\Models\DockerCleanupExecution;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DockerCleanupDone implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $teamId;

    public function __construct(public DockerCleanupExecution $execution)
    {
        $this->teamId = $execution->server?->team_id;
    }

    /**
     * A deleted server has no team to tell, so nothing is broadcast.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (is_null($this->teamId)) {
            return [];
        }

        return [new PrivateChannel("team.{$this->teamId}")];
    }

    /**
     * Keep the payload under Reverb's max message size: the cleanup log can be large.
     *
     * @return array{executionId: int, serverId: int, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'executionId' => $this->execution->id,
            'serverId' => $this->execution->server_id,
            'status' => $this->execution->status,
        ];
    }
}
