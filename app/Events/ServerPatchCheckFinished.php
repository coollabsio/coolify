<?php

namespace App\Events;

use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerPatchCheckFinished implements ShouldBroadcast
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(?int $teamId, public ?string $serverUuid = null)
    {
        $this->teamId = $teamId;
    }

    public function broadcastAs(): string
    {
        return 'ServerPatchCheckFinished';
    }

    /**
     * @return array{serverUuid: string|null}
     */
    public function broadcastWith(): array
    {
        return ['serverUuid' => $this->serverUuid];
    }
}
