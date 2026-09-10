<?php

use App\Ai\Support\PageContext;
use App\Enums\AiProvider;
use App\Jobs\Ai\RunAssistantTurn;
use App\Livewire\Ai\Thread;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
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

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'name' => 'my-web-app',
    ]);
});

test('resolves an application path into a block and stable key', function () {
    $path = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}/environment-variables";

    $resolved = PageContext::resolve($path);

    expect($resolved['key'])->toBe("application:{$this->application->uuid}:Environment Variables");
    expect($resolved['block'])
        ->toContain('Resource type: Application')
        ->toContain('my-web-app')
        ->toContain($this->application->uuid)
        ->toContain("uuid: {$this->project->uuid}")
        ->toContain("uuid: {$this->environment->uuid}")
        ->toContain('Environment Variables');
});

test('the key ignores status but the block keeps it', function () {
    $this->application->update(['status' => 'running:healthy']);
    $base = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}";

    $resolved = PageContext::resolve($base);

    expect($resolved['key'])->toBe("application:{$this->application->uuid}");
    expect($resolved['block'])->toContain('Status: running:healthy');
});

test('resolves a server path into a server block', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'prod-1']);

    $resolved = PageContext::resolve("/server/{$server->uuid}");

    expect($resolved['key'])->toBe("server:{$server->uuid}");
    expect($resolved['block'])->toContain('Resource type: Server')->toContain('prod-1');
});

test('does not leak resources owned by another team', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

    expect(PageContext::resolve("/project/{$otherProject->uuid}"))->toBeNull();
});

test('returns null for unknown, empty, or non-resource paths', function () {
    expect(PageContext::resolve(null))->toBeNull();
    expect(PageContext::resolve(''))->toBeNull();
    expect(PageContext::resolve('/dashboard'))->toBeNull();
    expect(PageContext::resolve('/this/route/does/not/exist'))->toBeNull();
});

test('embed, key extraction, and strip round-trip', function () {
    $embedded = PageContext::embed('application:abc123', 'the block body', 'restart this app');

    expect(PageContext::keyFromMessage($embedded))->toBe('application:abc123');
    expect(PageContext::strip($embedded))->toBe('restart this app');
    expect(PageContext::keyFromMessage('plain text'))->toBeNull();
    expect(PageContext::strip('plain text'))->toBe('plain text');
});

test('the first message on a page embeds the page block into the turn', function () {
    Bus::fake();
    $conversation = makeConversation($this->team, $this->user);
    $path = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}";

    Livewire::test(Thread::class, ['conversationId' => $conversation->id])
        ->call('sendPrompt', 'restart this app', $path)
        ->assertHasNoErrors();

    Bus::assertDispatched(RunAssistantTurn::class, fn ($job) => str_contains((string) $job->pageBlock, $this->application->uuid)
        && $job->pageKey === "application:{$this->application->uuid}"
        && $job->message === 'restart this app');
});

test('a message without a page path carries no page context', function () {
    Bus::fake();
    $conversation = makeConversation($this->team, $this->user);

    Livewire::test(Thread::class, ['conversationId' => $conversation->id])
        ->call('sendPrompt', 'hello')
        ->assertHasNoErrors();

    Bus::assertDispatched(RunAssistantTurn::class, fn ($job) => $job->pageBlock === null && $job->pageKey === null);
});

test('the job embeds the block only when the page changed', function () {
    $conversation = makeConversation($this->team, $this->user);
    $conversation->update(['sdk_conversation_id' => 'conv-1']);

    // Previous user message was already on this application page.
    insertMessage('conv-1', PageContext::embed("application:{$this->application->uuid}", 'old block', 'earlier question'));

    $job = new RunAssistantTurn($conversation->id, 'still here', $this->user->id, 'new block', "application:{$this->application->uuid}");

    // Same page -> bare message.
    expect(invadePrivate($job, 'messageWithPageContext', $conversation))->toBe('still here');

    // Different page -> embedded.
    $moved = new RunAssistantTurn($conversation->id, 'and now?', $this->user->id, 'new block', 'server:xyz');
    expect(invadePrivate($moved, 'messageWithPageContext', $conversation))
        ->toContain('<current_page id="server:xyz">')
        ->toContain('and now?');
});

test('the embedded block is stripped from the displayed transcript', function () {
    $conversation = makeConversation($this->team, $this->user);
    $conversation->update(['sdk_conversation_id' => 'conv-strip']);

    insertMessage('conv-strip', PageContext::embed('application:abc', 'hidden context', 'restart this app'));

    $component = Livewire::test(Thread::class, ['conversationId' => $conversation->id]);
    $messages = $component->instance()->messages();

    expect($messages[0]['content'])->toBe('restart this app');
    expect($messages[0]['content'])->not->toContain('current_page');
});

function makeConversation(Team $team, User $user): AiConversation
{
    AiProviderCredential::factory()->for($team)->create([
        'provider' => AiProvider::OPENAI, 'model' => 'gpt-5', 'is_default' => true, 'enabled' => true,
    ]);

    return AiConversation::factory()->for($team)->create([
        'created_by_user_id' => $user->id,
        'status' => AiConversation::STATUS_IDLE,
    ]);
}

function insertMessage(string $conversationId, string $content, string $role = 'user'): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::orderedUuid(),
        'conversation_id' => $conversationId,
        'agent' => 'coolify',
        'role' => $role,
        'content' => $content,
        'attachments' => '',
        'tool_calls' => '',
        'tool_results' => '',
        'usage' => '',
        'meta' => '',
    ]);
}

function invadePrivate(object $object, string $method, ...$args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}
