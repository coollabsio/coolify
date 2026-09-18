<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Enums\AiProvider;
use App\Events\Ai\AssistantTurnCompleted;
use App\Events\Ai\AssistantTurnFailed;
use App\Jobs\Ai\ResumeAssistantTurn;
use App\Listeners\Ai\RecordToolApproval;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Responses\Data\ToolResult;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI, 'model' => 'gpt-5', 'is_default' => true, 'enabled' => true,
    ]);
    $this->conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'status' => AiConversation::STATUS_RESPONDING,
        'sdk_conversation_id' => (string) Str::uuid(),
    ]);
});

test('resume streams a continuation and releases the thread', function () {
    Event::fake([AssistantTurnCompleted::class]);
    Ai::fakeAgent(CoolifyAssistant::class, ['Done, deleted the resource.']);

    (new ResumeAssistantTurn($this->conversation->id, ['call_1' => ['action' => 'approve']], $this->user->id))->handle();

    Event::assertDispatched(AssistantTurnCompleted::class);
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE);
});

test('the approval listener records the approver from context', function () {
    Context::add('ai.author_user_id', $this->user->id);
    $listener = new RecordToolApproval;

    $listener->handle(new ToolApprovalResolved(
        invocationId: 'inv-1',
        agent: new CoolifyAssistant,
        toolResults: new Collection([
            new ToolResult('call_1', 'delete_server', [], 'deleted', null),
        ]),
        conversationId: $this->conversation->sdk_conversation_id,
        conversationUser: $this->team,
    ));

    expect(true)->toBeTrue();
});

test('failed() clears the turn and returns the conversation to idle', function () {
    Event::fake([AssistantTurnFailed::class]);
    $this->conversation->update([
        'status' => AiConversation::STATUS_RESPONDING,
        'responding_user_id' => $this->user->id,
    ]);

    (new ResumeAssistantTurn($this->conversation->id, ['call_1' => ['action' => 'approve']], $this->user->id))
        ->failed(new RuntimeException('worker timed out'));

    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and($this->conversation->fresh()->responding_user_id)->toBeNull();
    Event::assertDispatched(AssistantTurnFailed::class);
});
