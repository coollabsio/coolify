<?php

use App\Livewire\Ai\Thread;
use App\Models\AiConversation;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

function seedPendingCreate(array $arguments): void
{
    $sdk = (string) Str::uuid();
    test()->conversation->update(['sdk_conversation_id' => $sdk]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid(),
        'conversation_id' => $sdk,
        'agent' => 'coolify',
        'role' => 'assistant',
        'content' => 'I need approval.',
        'attachments' => '[]',
        'tool_calls' => json_encode([[
            'id' => 'call_1',
            'name' => 'CreateDatabase', // ToolNameResolver = class basename
            'arguments' => $arguments,
        ]]),
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'approval_state' => json_encode(['pending' => ['call_1' => 'Create postgresql database.']]),
        'author_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('pending create approval carries a pre-filled editable form and seeds inputs', function () {
    seedPendingCreate([
        'type' => 'postgresql', 'name' => 'seed-pg',
        'project_uuid' => 'p1', 'environment_name' => 'production', 'server_uuid' => 's1',
    ]);

    $component = Livewire::test(Thread::class, ['conversationId' => $this->conversation->id]);
    $approvals = $component->instance()->pendingApprovals;

    expect($approvals)->toHaveCount(1)
        ->and($approvals[0]['form'])->not->toBeNull()
        ->and($approvals[0]['form']['title'])->toBe('Create database');

    $nameField = collect($approvals[0]['form']['fields'])->firstWhere('key', 'name');
    expect($nameField['value'])->toBe('seed-pg');

    // editable field values are seeded into approvalInputs, locked ones are not
    $inputs = $component->instance()->approvalInputs;
    expect($inputs['call_1']['name'])->toBe('seed-pg')
        ->and($inputs['call_1'])->not->toHaveKey('project_uuid');

    // the generic renderer compiles and renders the editable fields + locked target
    $html = $component->html();
    expect($html)->toContain('approvalInputs.call_1.name')
        ->and($html)->toContain('Deploy immediately')
        ->and($html)->toContain('Engine');
});
