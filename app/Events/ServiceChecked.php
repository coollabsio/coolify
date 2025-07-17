<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Contracts\Silenced;

class ServiceChecked implements ShouldBroadcast, Silenced
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?int $teamId = null;

    public function __construct($teamId = null)
    {
        if (is_null($teamId) && auth()->check() && auth()->user()->currentTeam()) {
            $teamId = auth()->user()->currentTeam()->id;
        }
        $this->teamId = $teamId;
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
