<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Support\AssistantTurn;
use App\Enums\AiProvider;
use App\Events\Ai\AssistantStreamDelta;
use App\Events\Ai\AssistantTurnCompleted;
use App\Events\Ai\AssistantTurnFailed;
use App\Jobs\Ai\RunAssistantTurn;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'status' => AiConversation::STATUS_RESPONDING,
    ]);
});

function seedCredential($team): void
{
    AiProviderCredential::factory()->for($team)->create([
        'provider' => AiProvider::OPENAI,
        'model' => 'gpt-5',
        'is_default' => true,
        'enabled' => true,
    ]);
}

test('the job streams sequenced deltas and completes, releasing the thread', function () {
    Event::fake([AssistantStreamDelta::class, AssistantTurnCompleted::class]);
    seedCredential($this->team);
    Ai::fakeAgent(CoolifyAssistant::class, ['All good here']);

    (new RunAssistantTurn($this->conversation->id, 'status?', $this->user->id))->handle();

    Event::assertDispatched(AssistantStreamDelta::class);
    Event::assertDispatched(AssistantTurnCompleted::class, fn ($e) => str_contains($e->text, 'All good'));
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and($this->conversation->fresh()->sdk_conversation_id)->not->toBeNull();
});

test('the job fails cleanly and releases when there is no credential', function () {
    Event::fake([AssistantTurnFailed::class]);

    (new RunAssistantTurn($this->conversation->id, 'hi', $this->user->id))->handle();

    Event::assertDispatched(AssistantTurnFailed::class);
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE);
});

test('a stop request halts the stream and releases', function () {
    Event::fake([AssistantStreamDelta::class, AssistantTurnCompleted::class]);
    seedCredential($this->team);
    AssistantTurn::requestStop($this->conversation->uuid);
    Ai::fakeAgent(CoolifyAssistant::class, ['one two three four five']);

    (new RunAssistantTurn($this->conversation->id, 'go', $this->user->id))->handle();

    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and(AssistantTurn::shouldStop($this->conversation->uuid))->toBeFalse();
});
