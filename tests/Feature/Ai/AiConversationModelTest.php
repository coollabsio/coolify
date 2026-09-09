<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Models\AiConversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsConcern;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

uses(RefreshDatabase::class);

test('an ai conversation belongs to a team and creator and auto-gets a uuid', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $conversation = AiConversation::create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'title' => 'First thread',
    ]);

    expect($conversation->uuid)->not->toBeNull()
        ->and($conversation->visibility)->toBe(AiConversation::VISIBILITY_PRIVATE)
        ->and($conversation->status)->toBe(AiConversation::STATUS_IDLE)
        ->and($conversation->team->id)->toBe($team->id)
        ->and($conversation->creator->id)->toBe($user->id);
});

test('ownedByCurrentTeam scopes to the active team', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    AiConversation::factory()->for($team)->create();
    AiConversation::factory()->for($other)->create();

    expect(AiConversation::ownedByCurrentTeam()->count())->toBe(1);
});

test('team exposes a conversations relation and the assistant remembers conversations', function () {
    expect(method_exists(Team::class, 'conversations'))->toBeTrue()
        ->and(new CoolifyAssistant)->toBeInstanceOf(RemembersConversationsContract::class)
        ->and(in_array(RemembersConversationsConcern::class, class_uses_recursive(CoolifyAssistant::class)))->toBeTrue();
});
