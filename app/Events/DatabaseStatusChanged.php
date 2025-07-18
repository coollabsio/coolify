<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class DatabaseStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int|string|null $userId = null;

    public function __construct($userId = null)
    {
        if (is_null($userId)) {
            $userId = Auth::id() ?? null;
        }
        $this->userId = $userId;
    }

    public function broadcastOn(): ?array
    {
        if (is_null($this->userId)) {
            return [];
        }

        return [
            new PrivateChannel("user.{$this->userId}"),
        ];
    }
}
