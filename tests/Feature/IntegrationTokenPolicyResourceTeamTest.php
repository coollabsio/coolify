<?php

use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tokenTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->token = IntegrationToken::factory()->create(['team_id' => $this->tokenTeam->id]);
    $this->user = User::factory()->create();
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->otherTeam]);
});

it('lets an admin of the token team manage the token while the session team is another team', function (string $ability) {
    $this->tokenTeam->members()->attach($this->user->id, ['role' => 'admin']);

    expect($this->user->fresh()->can($ability, $this->token))->toBeTrue();
})->with(['view', 'update', 'delete']);

it('denies a member of the token team who owns the session team', function (string $ability) {
    $this->tokenTeam->members()->attach($this->user->id, ['role' => 'member']);

    expect($this->user->fresh()->can($ability, $this->token))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('denies a user outside the token team even when the session points to the token team', function (string $ability) {
    session(['currentTeam' => $this->tokenTeam]);

    expect($this->user->fresh()->can($ability, $this->token))->toBeFalse();
})->with(['view', 'update', 'delete']);
