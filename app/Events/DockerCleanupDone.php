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

    public function __construct(public DockerCleanupExecution $execution) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->execution->server->team->id),
        ];
    }
}
