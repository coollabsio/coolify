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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk]);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'assistant',
        'content' => 'I need approval.',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'call_1', 'name' => 'create_service', 'arguments' => []]]),
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'approval_state' => json_encode(['pending' => ['call_1' => 'Create service.']]),
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('approve', 'call_1');

    Bus::assertDispatched(ResumeAssistantTurn::class, fn ($job) => $job->decisions === ['call_1' => ['action' => 'approve']]
        && $job->approverUserId === $this->user->id);
});

test('approving a non-pending call does not claim or dispatch', function () {
    Bus::fake();
    $this->conversation->update(['sdk_conversation_id' => (string) Str::uuid()]);

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('approve', 'bogus-call');

    Bus::assertNotDispatched(ResumeAssistantTurn::class);
    expect($this->conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and($this->conversation->fresh()->decision_log ?? [])->toBe([]);
});

test('the composer sends on Enter but not Shift+Enter and grows to a bounded height', function () {
    $html = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->html();

    expect($html)
        ->toContain('x-on:keydown.enter=')
        ->toContain('!$event.shiftKey')
        ->toContain('!$event.isComposing')
        ->toContain('submit()')
        ->toContain('x-on:input="autogrow()"')
        ->toContain('max-h-[200px]');
});

test('wide mode centers the conversation in a readable column', function () {
    $wide = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id, 'wide' => true])->html();
    $narrow = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->html();

    expect($wide)->toContain('max-w-3xl')
        ->and($narrow)->not->toContain('max-w-3xl');
});

test('the empty state fills the scroll area instead of forcing a scrollbar', function () {
    $html = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->html();

    // Scroll area is a flex column and the welcome state grows via flex-1. The old
    // `h-full` welcome block plus the container's py-4 overflowed and showed a scrollbar.
    expect($html)
        ->toContain('flex flex-1 min-h-0 flex-col overflow-y-auto')
        ->toContain('flex flex-1 flex-col items-center justify-center')
        ->not->toContain('flex h-full flex-col items-center justify-center');
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

test('the client cannot repoint the thread at another conversation (locked)', function () {
    $other = User::factory()->create();
    $other->teams()->attach($this->team, ['role' => 'member']);
    $foreign = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $other->id,
        'visibility' => AiConversation::VISIBILITY_PRIVATE,
    ]);

    // mount authorizes the caller's own conversation; #[Locked] then blocks any
    // client attempt to swap conversationId to a thread they cannot view.
    expect(fn () => Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->set('conversationId', $foreign->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('user messages carry the sender avatar and the viewer avatar is exposed', function () {
    $this->user->update(['name' => 'Alice Example']);
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'user',
        'content' => 'How many servers do I have?',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'author_user_id' => $this->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    $messages = $component->instance()->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('user')
        ->and($messages[0]['avatar']['initial'])->toBe('A')
        ->and($messages[0]['author'])->toBeNull() // own message: no name label
        ->and($component->instance()->viewer['initial'])->toBe('A');
});

test('a teammates message keeps its name label and resolves the id 0 root author', function () {
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk, 'visibility' => AiConversation::VISIBILITY_TEAM]);

    // Root user (id 0) is a valid sender; a truthy check would drop it (empty(0)).
    $root = User::factory()->create(['id' => 0, 'name' => 'Root User']);
    $root->teams()->attach($this->team, ['role' => 'admin']);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'user',
        'content' => 'Deploy the app',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'author_user_id' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->instance()->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['author'])->toBe('Root User')        // teammate: name label kept
        ->and($messages[0]['avatar']['initial'])->toBe('R');    // id 0 resolved, not dropped
});

test('the optimistic pending message is kept under wire:ignore so it survives re-renders', function () {
    $html = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->html();

    // The pending (optimistic) user message block is Alpine-owned and shielded
    // from Livewire morphs so it can't vanish while the agent is thinking.
    expect($html)->toContain('wire:ignore')
        ->and($html)->toContain('x-for="(text, index) in pending"');
});

test('a pending approval renders a confirm card with Accept and Cancel', function () {
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'assistant',
        'content' => 'I need approval.',
        'attachments' => '[]',
        'tool_calls' => json_encode([[
            'id' => 'call_1',
            'name' => 'delete_resource',
            'arguments' => ['uuid' => 'abc-123', 'name' => 'prod-db'],
        ]]),
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'approval_state' => json_encode(['pending' => ['call_1' => 'This permanently deletes the resource.']]),
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    $approvals = $component->instance()->pendingApprovals;

    expect($approvals)->toHaveCount(1)
        ->and($approvals[0]['title'])->toBe('Deleting resource')
        ->and($approvals[0]['destructive'])->toBeTrue()
        ->and($approvals[0]['reason'])->toBe('This permanently deletes the resource.');

    $html = $component->html();
    expect($html)
        ->toContain("approve('call_1')")
        ->and($html)->toContain("reject('call_1')")
        ->and($html)->toContain('Accept')
        ->and($html)->toContain('Cancel')
        ->and($html)->toContain('This permanently deletes the resource.')
        ->and($html)->toContain('Deleting resource');
});

test('a cancelled action leaves a persistent note in the transcript', function () {
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk]);

    // The SDK writes an empty-content assistant row with a denied tool result
    // when the user rejects an approval. It must surface as a persistent note,
    // not be filtered out for having no text.
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'c1', 'name' => 'DeleteResource', 'arguments' => ['uuid' => 'x']]]),
        'tool_results' => json_encode([['id' => 'c1', 'name' => 'DeleteResource', 'result' => 'The user rejected this tool call.', 'denied' => true]]),
        'usage' => '{}',
        'meta' => '{}',
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    $messages = $component->instance()->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('note')
        ->and($messages[0]['content'])->toBe('You cancelled this action.')
        ->and($component->html())->toContain('You cancelled this action.');
});

test('an empty intermediate tool-call row is still hidden', function () {
    $sdk = (string) Str::uuid();
    $this->conversation->update(['sdk_conversation_id' => $sdk]);

    // Approved/normal tool call: empty content, no denied result -> not shown.
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'c1', 'name' => 'ListServers', 'arguments' => []]]),
        'tool_results' => json_encode([['id' => 'c1', 'name' => 'ListServers', 'result' => 'ok', 'denied' => false]]),
        'usage' => '{}',
        'meta' => '{}',
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])->instance()->messages)
        ->toHaveCount(0);
});
