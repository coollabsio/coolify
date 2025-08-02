<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class ServiceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?int $teamId = null
    ) {
        if (is_null($this->teamId) && Auth::check() && Auth::user()->currentTeam()) {
            $this->teamId = Auth::user()->currentTeam()->id;
        }
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
