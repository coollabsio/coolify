<?php

namespace App\Events;

use App\Models\Server;
use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SentinelSynchronized implements ShouldBroadcast
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;

    public string $serverUuid;

    public function __construct(Server $server)
    {
        $this->teamId = $server->team_id;
        $this->serverUuid = $server->uuid;
    }
}
