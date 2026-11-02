<?php

namespace App\Events;

use App\Models\Server;
use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A step of the "Finish the transfer" list changed, so open server pages show it without a reload.
 */
class TransferFinishChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets;

    public string $serverUuid;

    public function __construct(Server $server)
    {
        $this->teamId = $server->team_id;
        $this->serverUuid = $server->uuid;
    }
}
