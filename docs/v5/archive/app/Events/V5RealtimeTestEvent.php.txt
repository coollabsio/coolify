<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class V5RealtimeTestEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $sentAt;

    public function __construct(public int $teamId, public string $message)
    {
        $this->sentAt = now()->toJSON();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("team.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'v5.realtime.test';
    }

    /**
     * @return array{message: string, teamId: int, sentAt: string}
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'teamId' => $this->teamId,
            'sentAt' => $this->sentAt,
        ];
    }
}
