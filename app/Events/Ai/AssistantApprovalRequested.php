<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantApprovalRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Pure refresh signal: the client re-fetches pending approvals from the DB on
    // this event, so we never broadcast raw tool arguments (which can carry
    // secrets or commands) to conversation viewers.
    public function __construct(
        public string $conversationUuid,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("ai-conversation.{$this->conversationUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'assistant.approval';
    }
}
