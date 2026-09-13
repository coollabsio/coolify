<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantConversationRenamed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $teamId,
        public int $conversationId,
        public string $title,
        public string $visibility = 'team',
        public ?int $ownerUserId = null,
    ) {}

    /**
     * A private conversation's title (derived from its first message) must not
     * leak to the whole team, so it is broadcast only to its owner. Shared
     * conversations broadcast to the team so every member's sidebar updates.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->visibility === 'team') {
            return [new PrivateChannel("team.{$this->teamId}")];
        }

        return $this->ownerUserId
            ? [new PrivateChannel("user.{$this->ownerUserId}")]
            : [];
    }

    public function broadcastAs(): string
    {
        return 'assistant.conversation.renamed';
    }
}
