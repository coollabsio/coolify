<?php

namespace App\Events;

use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ImageScanUpdated implements ShouldBroadcast
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(?int $teamId, public string $resourceUuid, public string $scanUuid)
    {
        $this->teamId = $teamId;
    }

    public function broadcastAs(): string
    {
        return 'ImageScanUpdated';
    }

    /**
     * @return array{resourceUuid: string, scanUuid: string}
     */
    public function broadcastWith(): array
    {
        return ['resourceUuid' => $this->resourceUuid, 'scanUuid' => $this->scanUuid];
    }
}
