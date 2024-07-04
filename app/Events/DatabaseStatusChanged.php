<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $userId = null;

    public function __construct($userId = null)
    {
        if (is_null($userId)) {
            $userId = auth()->user()->id ?? null;
        }
        if (is_null($userId)) {
            return false;
        }
        $this->userId = $userId;
    }

    public function broadcastOn(): ?array
    {
        if ($this->userId) {
            return [
                new PrivateChannel("user.{$this->userId}"),
            ];
        }

        return null;
    }
}
