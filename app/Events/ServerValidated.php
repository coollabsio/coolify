<?php

namespace App\Events;

use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerValidated implements ShouldBroadcast
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $serverUuid = null;

    public function __construct(?int $teamId, ?string $serverUuid = null)
    {
        $this->teamId = $teamId;
        $this->serverUuid = $serverUuid;
    }

    public function broadcastAs(): string
    {
        return 'ServerValidated';
    }

    public function broadcastWith(): array
    {
        return [
            'teamId' => $this->teamId,
            'serverUuid' => $this->serverUuid,
        ];
    }
}
