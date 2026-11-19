<?php

namespace App\Events;

use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProxyStatusChangedUI implements ShouldBroadcast
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $activityId = null;

    public function __construct(?int $teamId, ?int $activityId = null)
    {
        $this->teamId = $teamId;
        $this->activityId = $activityId;
    }
}
