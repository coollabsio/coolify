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

    /**
     * @param  bool  $final  False for a progress update of a running scan; pages that show only results ignore it.
     */
    public function __construct(?int $teamId, public string $resourceUuid, public string $scanUuid, public bool $final = true)
    {
        $this->teamId = $teamId;
    }

    public function broadcastAs(): string
    {
        return 'ImageScanUpdated';
    }

    /**
     * @return array{resourceUuid: string, scanUuid: string, final: bool}
     */
    public function broadcastWith(): array
    {
        return ['resourceUuid' => $this->resourceUuid, 'scanUuid' => $this->scanUuid, 'final' => $this->final];
    }
}
