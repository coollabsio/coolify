<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerValidated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $teamId = null;

    public ?string $serverUuid = null;

    public function __construct(?int $teamId = null, ?string $serverUuid = null)
    {
        if (is_null($teamId) && auth()->check() && auth()->user()->currentTeam()) {
            $teamId = auth()->user()->currentTeam()->id;
        }
        $this->teamId = $teamId;
        $this->serverUuid = $serverUuid;
    }

    public function broadcastOn(): array
    {
        if (is_null($this->teamId)) {
            return [];
        }

        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
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
