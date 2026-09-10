<?php

use App\Ai\Support\AssistantTurn;
use App\Events\Ai\AssistantApprovalRequested;
use App\Events\Ai\AssistantReasoningDelta;
use App\Events\Ai\AssistantStreamDelta;
use App\Events\Ai\AssistantTurnCompleted;
use App\Events\Ai\AssistantTurnFailed;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

test('streaming events broadcast synchronously so tokens arrive in realtime', function (string $event) {
    expect(is_subclass_of($event, ShouldBroadcastNow::class))->toBeTrue();
})->with([
    AssistantStreamDelta::class,
    AssistantReasoningDelta::class,
    AssistantTurnCompleted::class,
    AssistantTurnFailed::class,
    AssistantApprovalRequested::class,
]);

test('the reasoning delta broadcasts cumulative text on the conversation channel', function () {
    $event = new AssistantReasoningDelta('abc-uuid', 3, 'thinking about disk usage');

    expect($event->broadcastAs())->toBe('assistant.reasoning')
        ->and($event->reasoning)->toBe('thinking about disk usage')
        ->and($event->broadcastOn()[0]->name)->toBe('private-ai-conversation.abc-uuid');
});

test('reasoning is cached per turn and cleared when the turn finalizes', function () {
    $uuid = 'turn-uuid';

    AssistantTurn::putReasoning($uuid, 'step one');
    AssistantTurn::putReasoning($uuid, 'step one, step two');
    expect(AssistantTurn::getReasoning($uuid))->toBe('step one, step two');

    AssistantTurn::clear($uuid);
    expect(AssistantTurn::getReasoning($uuid))->toBe('');
});
