<?php

use App\Ai\Support\PageContext;
use App\Events\Ai\AssistantConversationRenamed;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiConversation;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
});

test('falls back to the truncated prompt when no AI credential is configured', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => null,
    ]);

    (new GenerateConversationTitle(
        $conversation->id,
        '  How do I fix my crashing postgres database on the production server right now?  ',
        $this->user->id,
    ))->handle();

    $title = $conversation->fresh()->title;

    expect($title)->not->toBeNull()
        ->and($title)->toStartWith('How do I fix my')
        ->and(mb_strlen($title))->toBeLessThanOrEqual(51); // Str::limit(48) + ellipsis
});

test('does nothing when the conversation already has a title', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Existing title',
    ]);

    (new GenerateConversationTitle($conversation->id, 'a brand new prompt', $this->user->id))->handle();

    expect($conversation->fresh()->title)->toBe('Existing title');
});

test('strips the page-context block before building the fallback title', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => null,
    ]);

    $message = PageContext::embed(
        'server-localhost',
        'Server: localhost',
        'Restart the proxy',
    );

    (new GenerateConversationTitle($conversation->id, $message, $this->user->id))->handle();

    $title = $conversation->fresh()->title;

    expect($title)->toBe('Restart the proxy')
        ->and($title)->not->toContain('current_page');
});

test('uses a sensible default when the prompt is empty', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => null,
    ]);

    (new GenerateConversationTitle($conversation->id, '   ', $this->user->id))->handle();

    expect($conversation->fresh()->title)->toBe('New conversation');
});

test('a private title broadcasts only to its owner, never the whole team', function () {
    Event::fake([AssistantConversationRenamed::class]);
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'title' => null,
        'visibility' => AiConversation::VISIBILITY_PRIVATE,
    ]);

    (new GenerateConversationTitle($conversation->id, 'Fix my crashing database', $this->user->id))->handle();

    Event::assertDispatched(AssistantConversationRenamed::class, function ($event) {
        $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

        return $event->visibility === 'private'
            && $channels->contains("private-user.{$this->user->id}")
            && ! $channels->contains("private-team.{$this->team->id}");
    });
});

test('a shared title broadcasts to the team', function () {
    Event::fake([AssistantConversationRenamed::class]);
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'title' => null,
        'visibility' => AiConversation::VISIBILITY_TEAM,
    ]);

    (new GenerateConversationTitle($conversation->id, 'Team-wide question', $this->user->id))->handle();

    Event::assertDispatched(AssistantConversationRenamed::class, function ($event) {
        $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

        return $channels->contains("private-team.{$this->team->id}");
    });
});

test('a private conversation owned by the root user (id 0) still broadcasts to its owner', function () {
    // Regression: empty(0) is true, so a bare truthiness check would drop the
    // broadcast for the root user (id 0).
    $event = new AssistantConversationRenamed(
        teamId: 1,
        conversationId: 2,
        title: 'Root thread',
        visibility: 'private',
        ownerUserId: 0,
    );

    $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $c) => $c->name);

    expect($channels)->toContain('private-user.0');
});
