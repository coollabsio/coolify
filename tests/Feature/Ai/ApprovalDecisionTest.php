<?php

use App\Jobs\Ai\ResumeAssistantTurn;
use App\Livewire\Ai\Thread;
use App\Models\AiConversation;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
    $this->conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'status' => AiConversation::STATUS_IDLE,
    ]);

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
            'name' => 'CreateDatabase',
            'arguments' => ['type' => 'postgresql', 'name' => 'orig-pg', 'project_uuid' => 'p1', 'server_uuid' => 's1', 'environment_name' => 'production'],
        ]]),
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'approval_state' => json_encode(['pending' => ['call_1' => 'Create postgresql database.']]),
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('approving with an edited field resumes with Decision edit and merged arguments', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->set('approvalInputs.call_1.name', 'edited-pg')
        ->call('approve', 'call_1');

    Bus::assertDispatched(ResumeAssistantTurn::class, function ($job) {
        $d = $job->decisions['call_1'];

        return $d['action'] === 'edit'
            && $d['arguments']['name'] === 'edited-pg'
            && $d['arguments']['project_uuid'] === 'p1'; // original preserved
    });
});

test('rejecting resumes with a reject decision', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('reject', 'call_1');

    Bus::assertDispatched(ResumeAssistantTurn::class, fn ($job) => $job->decisions['call_1'] === ['action' => 'reject']);
});

test('accepting hides the approval card immediately without waiting for the turn', function () {
    Bus::fake();

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    expect($component->instance()->pendingApprovals())->toHaveCount(1);

    $component->call('approve', 'call_1');
    expect($component->instance()->pendingApprovals())->toHaveCount(0);
});

test('rejecting hides the approval card immediately without waiting for the turn', function () {
    Bus::fake();

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    expect($component->instance()->pendingApprovals())->toHaveCount(1);

    $component->call('reject', 'call_1');
    expect($component->instance()->pendingApprovals())->toHaveCount(0);
});

test('accepting records an approved note in the transcript immediately', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->assertDontSee('Approved: Create postgresql database.')
        ->call('approve', 'call_1')
        ->assertSee('Approved: Create postgresql database.');

    expect($this->conversation->fresh()->decision_log['call_1'])
        ->toMatchArray(['decision' => 'approved', 'reason' => 'Create postgresql database.']);
});

test('rejecting records a cancelled note in the transcript immediately', function () {
    Bus::fake();

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->call('reject', 'call_1')
        ->assertSee('Cancelled: Create postgresql database.');
});

test('a resolved approval row shows the informative note after reload', function () {
    $this->conversation->update(['decision_log' => [
        'call_1' => ['decision' => 'approved', 'reason' => 'Create postgresql database.'],
    ]]);
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversation->sdk_conversation_id)
        ->update([
            'tool_results' => json_encode([['id' => 'call_1', 'name' => 'create_service', 'result' => 'Created service.']]),
            'approval_state' => json_encode(['pending' => []]),
        ]);

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->assertSee('Approved: Create postgresql database.')
        ->assertDontSee('You approved this action.');
});

test('a resolved rejection row shows the cancelled note after reload', function () {
    DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversation->sdk_conversation_id)
        ->update([
            'tool_results' => json_encode([['id' => 'call_1', 'name' => 'create_service', 'denied' => true, 'result' => 'Cancelled.']]),
            'approval_state' => json_encode(['pending' => []]),
        ]);

    Livewire::test(Thread::class, ['conversationId' => $this->conversation->id])
        ->assertSee('You cancelled this action.');
});
