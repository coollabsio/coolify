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

    public ?string $userId = null;

    public function __construct($userId = null)
    {
        if (blank($userId)) {
            $userId = Auth::id() ?? null;
        }
        if (blank($userId)) {
            return false;
        }
        $this->userId = $userId;
    }

    public function broadcastOn(): ?array
    {
        if (filled($this->userId)) {
            return [
                new PrivateChannel("user.{$this->userId}"),
            ];
        }

        return null;
    }
}
