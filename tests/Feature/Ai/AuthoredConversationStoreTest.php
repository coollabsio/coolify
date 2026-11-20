<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Storage\AuthoredConversationStore;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    config(['ai.providers.byok-test' => [
        'driver' => 'openai-compatible',
        'url' => 'http://prov.test/v1',
        'key' => 'byok-secret',
        'models' => ['text' => ['default' => 'test-model']],
    ]]);

    Http::fake(['prov.test/*' => Http::response([
        'id' => 'x',
        'object' => 'chat.completion',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello!'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])]);
});

test('the conversation store singleton is the authored store', function () {
    expect(app(ConversationStore::class))->toBeInstanceOf(AuthoredConversationStore::class);
});

test('a user turn is stamped with the author and the assistant turn is not', function () {
    Context::add('ai.author_user_id', $this->user->id);

    (new CoolifyAssistant)
        ->forParticipant($this->team)
        ->prompt('How many servers do I have?', provider: 'byok-test', model: 'test-model');

    $conversation = DB::table('agent_conversations')->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->participant_type)->toBe((new Team)->getMorphClass())
        ->and((int) $conversation->participant_id)->toBe($this->team->id);

    $userRow = DB::table('agent_conversation_messages')->where('role', 'user')->first();
    $assistantRow = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

    expect((int) $userRow->author_user_id)->toBe($this->user->id)
        ->and($assistantRow->author_user_id)->toBeNull();
});
