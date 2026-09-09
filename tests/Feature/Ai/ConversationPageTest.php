<?php

use App\Livewire\Ai\ConversationPage;
use App\Models\AiConversation;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('new thread creates a private conversation owned by the current user', function () {
    Livewire::test(ConversationPage::class)->call('newThread');

    $conversation = AiConversation::where('team_id', $this->team->id)->latest('id')->first();
    expect($conversation->created_by_user_id)->toBe($this->user->id)
        ->and($conversation->visibility)->toBe(AiConversation::VISIBILITY_PRIVATE);
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
