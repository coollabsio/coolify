<?php

use App\Models\AiConversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->creator = User::factory()->create();
    $this->creator->teams()->attach($this->team, ['role' => 'member']);
    $this->teammate = User::factory()->create();
    $this->teammate->teams()->attach($this->team, ['role' => 'member']);
});

test('a private thread is visible only to its creator', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_PRIVATE,
    ]);

    expect($this->creator->can('view', $conversation))->toBeTrue()
        ->and($this->teammate->can('view', $conversation))->toBeFalse();
});

test('a team thread is visible to any team member', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_TEAM,
    ]);

    expect($this->teammate->can('view', $conversation))->toBeTrue();
});

test('a user from another team can never view the thread', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_TEAM,
    ]);
    $outsider = User::factory()->create();
    $outsider->teams()->attach(Team::factory()->create(), ['role' => 'admin']);

    expect($outsider->can('view', $conversation))->toBeFalse();
});

test('only the creator can update or delete the thread', function () {
    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_TEAM,
    ]);

    expect($this->creator->can('update', $conversation))->toBeTrue()
        ->and($this->creator->can('delete', $conversation))->toBeTrue()
        ->and($this->teammate->can('update', $conversation))->toBeFalse()
        ->and($this->teammate->can('delete', $conversation))->toBeFalse();
});
