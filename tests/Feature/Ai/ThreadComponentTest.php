<?php

use App\Ai\Support\AssistantTurn;
use App\Enums\AiProvider;
use App\Jobs\Ai\ResumeAssistantTurn;
use App\Jobs\Ai\RunAssistantTurn;
use App\Livewire\Ai\Thread;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

test('sending a message starts a turn and clears the composer', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->set('composerMessage', 'How many servers?')
        ->call('send')
        ->assertSet('composerMessage', '')
        ->assertHasNoErrors();

    Bus::assertDispatched(RunAssistantTurn::class);
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_RESPONDING);
});

test('a suggestion prompt starts a turn with the chosen text', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('sendPrompt', 'List my servers and their status')
        ->assertSet('composerMessage', '')
        ->assertHasNoErrors();

    Bus::assertDispatched(RunAssistantTurn::class);
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_RESPONDING);
});

test('an empty message does not start a turn', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->set('composerMessage', '   ')
        ->call('send');

    Bus::assertNotDispatched(RunAssistantTurn::class);
});

test('stop requests cancellation for the thread', function () {
    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('stop');

    expect(AssistantTurn::shouldStop($this->conversation->uuid))->toBeTrue();
});

test('approving a pending call dispatches a resume job', function () {
    Bus::fake();
    $this->conversation->update(['sdk_conversation_id' => (string) Str::uuid()]);

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('approve', 'call_1');

    Bus::assertDispatched(ResumeAssistantTurn::class, fn ($job) => $job->decisions === ['call_1' => true]
        && $job->approverUserId === $this->user->id);
});

test('a member cannot open another members private thread', function () {
    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'member']);
    $private = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'visibility' => AiConversation::VISIBILITY_PRIVATE,
    ]);
    $this->actingAs($other);
    session(['currentTeam' => ['id' => $this->team->id]]);
    $this->withoutExceptionHandling();

    expect(fn () => Livewire::test(Thread::class, ['conversationId' => $private->id]))
        ->toThrow(AuthorizationException::class);
});
