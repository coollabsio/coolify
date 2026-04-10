<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProxyStatusChangedUI implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $teamId = null;

    public ?int $activityId = null;

    public function __construct(?int $teamId = null, ?int $activityId = null)
    {
        if (is_null($teamId) && auth()->check() && auth()->user()->currentTeam()) {
            $teamId = auth()->user()->currentTeam()->id;
        }
        $this->teamId = $teamId;
        $this->activityId = $activityId;
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
}
