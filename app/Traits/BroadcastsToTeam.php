<?php

namespace App\Traits;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * Broadcasts an event on the private channel of an explicit team.
 *
 * The team must come from the resource the event is about, never from the session:
 * the session team can differ from the resource team (e.g. after a team switch in
 * another tab), and queued jobs have no session at all.
 */
trait BroadcastsToTeam
{
    public ?int $teamId = null;

    public function __construct(?int $teamId)
    {
        $this->teamId = $teamId;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (is_null($this->teamId)) {
            return [];
        }

        return [new PrivateChannel("team.{$this->teamId}")];
    }
}
