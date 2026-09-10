<?php

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Streams the model's reasoning/thinking. Carries the cumulative reasoning text
 * so any single frame is self-contained (reordering or a missed frame cannot
 * blank the accordion — a later frame always supersedes an earlier one).
 */
class AssistantReasoningDelta implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $conversationUuid,
        public int $sequence,
        public string $reasoning,
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
        return 'assistant.reasoning';
    }
}
