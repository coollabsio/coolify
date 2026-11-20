<?php

use App\Ai\Support\AssistantTurn;
use App\Enums\AiProvider;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('only the first claimant wins the idle to responding transition', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $conversation = AiConversation::factory()->for($team)->create([
        'status' => AiConversation::STATUS_IDLE,
    ]);

    expect($conversation->claim($user))->toBeTrue()
        ->and($conversation->fresh()->status)->toBe(AiConversation::STATUS_RESPONDING)
        ->and($conversation->fresh()->responding_user_id)->toBe($user->id)
        ->and($conversation->fresh()->claim(User::factory()->create()))->toBeFalse();

    $conversation->fresh()->release();
    expect($conversation->fresh()->status)->toBe(AiConversation::STATUS_IDLE)
        ->and($conversation->fresh()->responding_user_id)->toBeNull();
});

test('the turn cache tracks sequence, partial, and cancellation', function () {
    $uuid = 'conv-x';

    expect(AssistantTurn::nextSequence($uuid))->toBe(1)
        ->and(AssistantTurn::nextSequence($uuid))->toBe(2);

    AssistantTurn::putPartial($uuid, 'hello world');
    expect(AssistantTurn::getPartial($uuid))->toBe('hello world');

    expect(AssistantTurn::shouldStop($uuid))->toBeFalse();
    AssistantTurn::requestStop($uuid);
    expect(AssistantTurn::shouldStop($uuid))->toBeTrue();

    AssistantTurn::clear($uuid);
    expect(AssistantTurn::getPartial($uuid))->toBe('')
        ->and(AssistantTurn::shouldStop($uuid))->toBeFalse();
});

test('defaultForTeam prefers the enabled default credential', function () {
    $team = Team::factory()->create();
    AiProviderCredential::factory()->for($team)->create(['is_default' => false, 'enabled' => true, 'model' => 'a']);
    $default = AiProviderCredential::factory()->for($team)->create(['is_default' => true, 'enabled' => true, 'provider' => AiProvider::OPENAI, 'model' => 'b']);
    AiProviderCredential::factory()->for($team)->create(['is_default' => true, 'enabled' => false, 'model' => 'c']);

    expect(AiProviderCredential::defaultForTeam($team->id)->id)->toBe($default->id);
});
