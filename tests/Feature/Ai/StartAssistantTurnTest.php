<?php

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantDisabledException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\StartAssistantTurn;
use App\Enums\AiProvider;
use App\Jobs\Ai\RunAssistantTurn;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
    AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI, 'model' => 'gpt-5', 'is_default' => true, 'enabled' => true,
    ]);
    $this->conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'status' => AiConversation::STATUS_IDLE,
    ]);
});

test('starting a turn claims the thread and dispatches the job', function () {
    Bus::fake();

    app(StartAssistantTurn::class)->handle($this->conversation, $this->user, 'Hello');

    Bus::assertDispatched(RunAssistantTurn::class, fn ($job) => $job->conversationId === $this->conversation->id
        && $job->userId === $this->user->id
        && $job->message === 'Hello');
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_RESPONDING);
});

test('a busy thread refuses a second turn', function () {
    Bus::fake();
    $this->conversation->update(['status' => AiConversation::STATUS_RESPONDING]);

    expect(fn () => app(StartAssistantTurn::class)->handle($this->conversation, $this->user, 'Hi'))
        ->toThrow(AssistantBusyException::class);
    Bus::assertNotDispatched(RunAssistantTurn::class);
});

test('a team with the assistant disabled cannot start a turn', function () {
    Bus::fake();
    $this->team->update(['is_ai_assistant_enabled' => false]);

    expect(fn () => app(StartAssistantTurn::class)->handle($this->conversation, $this->user, 'Hi'))
        ->toThrow(AssistantDisabledException::class);
    Bus::assertNotDispatched(RunAssistantTurn::class);
});

test('the per-user rate limit is enforced', function () {
    Bus::fake();
    $key = 'ai-turn:'.$this->team->id.':'.$this->user->id;
    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit($key, 60);
    }

    expect(fn () => app(StartAssistantTurn::class)->handle($this->conversation, $this->user, 'Hi'))
        ->toThrow(AssistantRateLimitedException::class);
});
