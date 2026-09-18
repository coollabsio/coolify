<?php

use App\Enums\AiProvider;
use App\Jobs\Ai\RunAssistantTurn;
use App\Livewire\Ai\ConversationPage;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->mate = User::factory()->create();
    $this->mate->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('the sidebar lists team threads and my own private threads only', function () {
    $mine = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'visibility' => 'private']);
    $shared = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->mate->id, 'visibility' => 'team']);
    $matesPrivate = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->mate->id, 'visibility' => 'private']);

    $ids = collect(Livewire::test(ConversationPage::class)->instance()->threads)->pluck('id');

    expect($ids)->toContain($mine->id)->toContain($shared->id)->not->toContain($matesPrivate->id);
});

test('the page renders the house stacked sidebar and a header for the active thread', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'private', 'title' => 'Disk usage help',
    ]);

    $html = Livewire::test(ConversationPage::class, ['uuid' => $conversation->uuid])->html();

    expect($html)
        ->toContain('New conversation')
        ->toContain('menu-item')          // house sidebar row vocabulary
        ->toContain('menu-item-active')   // active thread pill
        ->toContain('Disk usage help')    // title in both sidebar and header
        ->toContain('bg-coollabs text-white') // shared <x-ai.avatar> identity glyph in the header
        ->toContain('Share with team');   // private + mine offers sharing
});

test('a private thread offers share behind a confirm popover', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'private', 'title' => 'Disk usage help',
    ]);

    $html = Livewire::test(ConversationPage::class, ['uuid' => $conversation->uuid])->html();

    expect($html)
        ->not->toContain('>Team<')                              // no shared pill for a private thread
        ->and($html)->toContain('x-data="{ open: false }"')     // confirm popover wrapper
        ->and($html)->toContain('Share this conversation with your team')
        ->and($html)->toContain('wire:click="share(');
});

test('a shared thread shows the shared pill and offers unshare in the popover', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team', 'title' => 'Team thread',
    ]);

    $html = Livewire::test(ConversationPage::class, ['uuid' => $conversation->uuid])->html();

    expect($html)
        ->toContain('>Team<')                                   // shared pill
        ->and($html)->toContain('wire:click="unshare(')         // unshare action available
        ->and($html)->toContain('Make private');                // popover confirm button
});

test('unshare flips a shared conversation back to private for the creator', function () {
    $shared = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team',
    ]);

    Livewire::test(ConversationPage::class)->call('unshare', $shared->id);

    expect($shared->fresh()->visibility)->toBe(AiConversation::VISIBILITY_PRIVATE);
});

test('a member cannot unshare another users shared conversation', function () {
    $shared = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team',
    ]);
    $this->actingAs($this->mate);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ConversationPage::class)->call('unshare', $shared->id)->assertForbidden();

    expect($shared->fresh()->visibility)->toBe(AiConversation::VISIBILITY_TEAM);
});

test('new thread goes to the default composer without creating a conversation', function () {
    Livewire::test(ConversationPage::class)
        ->call('newThread')
        ->assertRedirect(route('ai.assistant'));

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(0);
});

test('share flips visibility to team for the creator only', function () {
    $mine = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'visibility' => 'private']);

    Livewire::test(ConversationPage::class)->call('share', $mine->id);
    expect($mine->fresh()->visibility)->toBe(AiConversation::VISIBILITY_TEAM);
});

test('a member cannot delete another users thread', function () {
    $mine = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'visibility' => 'team']);
    $this->actingAs($this->mate);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ConversationPage::class)->call('deleteThread', $mine->id);

    expect(AiConversation::find($mine->id))->not->toBeNull();
});

test('rename updates the title for the creator', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Old title',
    ]);

    Livewire::test(ConversationPage::class)
        ->call('startRename', $mine->id)
        ->assertSet('editingId', $mine->id)
        ->set('editingTitle', '  Disk cleanup on prod  ')
        ->call('rename')
        ->assertSet('editingId', null);

    expect($mine->fresh()->title)->toBe('Disk cleanup on prod');
});

test('rename ignores an empty title', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Keep me',
    ]);

    Livewire::test(ConversationPage::class)
        ->call('startRename', $mine->id)
        ->set('editingTitle', '   ')
        ->call('rename');

    expect($mine->fresh()->title)->toBe('Keep me');
});

test('a member cannot rename another users thread', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team', 'title' => 'Original',
    ]);
    $this->actingAs($this->mate);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ConversationPage::class)->call('startRename', $mine->id)->assertForbidden();

    expect($mine->fresh()->title)->toBe('Original');
});

test('togglePin pins then unpins a conversation and sorts pinned first', function () {
    $a = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'title' => 'A', 'updated_at' => now()->subMinutes(1)]);
    $b = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'title' => 'B', 'updated_at' => now()]);

    // Pin the older one; it should jump to the top.
    Livewire::test(ConversationPage::class)->call('togglePin', $a->id);
    expect($a->fresh()->pinned_at)->not->toBeNull();

    $order = collect(Livewire::test(ConversationPage::class)->instance()->threads)->pluck('id');
    expect($order->first())->toBe($a->id);

    // Unpin returns it below the more-recent B.
    Livewire::test(ConversationPage::class)->call('togglePin', $a->id);
    expect($a->fresh()->pinned_at)->toBeNull();
    $order = collect(Livewire::test(ConversationPage::class)->instance()->threads)->pluck('id');
    expect($order->first())->toBe($b->id);
});

test('a member cannot pin another users thread', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team',
    ]);
    $this->actingAs($this->mate);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ConversationPage::class)->call('togglePin', $mine->id)->assertForbidden();

    expect($mine->fresh()->pinned_at)->toBeNull();
});

test('the sidebar row offers rename, pin and delete for the creator', function () {
    AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'My thread',
    ]);

    $html = Livewire::test(ConversationPage::class)->html();

    expect($html)
        ->toContain('wire:click="startRename(')
        ->and($html)->toContain('wire:click="togglePin(')
        ->and($html)->toContain('Conversation options');
});

test('archive hides a conversation from the active list and shows it under archived', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Old chat', 'pinned_at' => now(),
    ]);

    Livewire::test(ConversationPage::class)->call('archive', $mine->id);

    $fresh = $mine->fresh();
    expect($fresh->archived_at)->not->toBeNull()
        ->and($fresh->pinned_at)->toBeNull(); // archiving also unpins

    $page = Livewire::test(ConversationPage::class);
    expect(collect($page->instance()->threads)->pluck('id'))->not->toContain($mine->id)
        ->and(collect($page->instance()->archivedThreads)->pluck('id'))->toContain($mine->id);
});

test('unarchive returns a conversation to the active list', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'archived_at' => now(),
    ]);

    Livewire::test(ConversationPage::class)->call('unarchive', $mine->id);

    expect($mine->fresh()->archived_at)->toBeNull();
    $page = Livewire::test(ConversationPage::class);
    expect(collect($page->instance()->threads)->pluck('id'))->toContain($mine->id);
});

test('archiving the open conversation redirects to the default assistant page', function () {
    $b = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id, 'title' => 'B']);

    Livewire::test(ConversationPage::class, ['uuid' => $b->uuid])
        ->call('archive', $b->id)
        ->assertRedirect(route('ai.assistant'));

    expect($b->fresh()->archived_at)->not->toBeNull();
});

test('opening a conversation navigates to its per-session url', function () {
    $c = AiConversation::factory()->for($this->team)->create(['created_by_user_id' => $this->user->id]);

    Livewire::test(ConversationPage::class)
        ->call('open', $c->id)
        ->assertRedirect(route('ai.assistant.show', ['uuid' => $c->uuid]));
});

test('a member cannot archive another users thread', function () {
    $mine = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'visibility' => 'team',
    ]);
    $this->actingAs($this->mate);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(ConversationPage::class)->call('archive', $mine->id)->assertForbidden();

    expect($mine->fresh()->archived_at)->toBeNull();
});

test('the sidebar shows an archived section with unarchive when expanded', function () {
    AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Active thread',
    ]);
    AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Buried thread', 'archived_at' => now(),
    ]);

    $html = Livewire::test(ConversationPage::class)
        ->set('showArchived', true)
        ->html();

    expect($html)
        ->toContain('Archived (1)')
        ->and($html)->toContain('wire:click="unarchive(')
        ->and($html)->toContain('Buried thread')
        ->and($html)->toContain('wire:click="archive('); // archive available on active rows too
});

test('the default page shows a welcoming composer with suggestions', function () {
    $html = Livewire::test(ConversationPage::class)->html();

    expect($html)
        ->toContain('How can I help?')
        ->and($html)->toContain('Message the assistant')            // composer visible
        ->and($html)->toContain('startConversation')                // submit wires to it
        ->and($html)->toContain('Give me an overview of my infrastructure'); // default option
});

test('startConversation creates a conversation, starts a turn, and redirects to it', function () {
    Bus::fake();
    AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI, 'model' => 'gpt-5', 'is_default' => true, 'enabled' => true,
    ]);

    Livewire::test(ConversationPage::class)
        ->call('startConversation', 'How many servers do I have?');

    $conversation = AiConversation::where('team_id', $this->team->id)->latest('id')->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->status)->toBe(AiConversation::STATUS_RESPONDING);
    Bus::assertDispatched(RunAssistantTurn::class);
});

test('startConversation with no credential does not leave an empty conversation', function () {
    Livewire::test(ConversationPage::class)
        ->call('startConversation', 'Hello');

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(0);
});
