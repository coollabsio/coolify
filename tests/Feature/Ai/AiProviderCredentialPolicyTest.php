<?php

use App\Models\AiProviderCredential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->cred = AiProviderCredential::factory()->for($this->team)->create();
});

test('admin can manage credentials', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);
    expect($this->admin->can('create', AiProviderCredential::class))->toBeTrue()
        ->and($this->admin->can('update', $this->cred))->toBeTrue()
        ->and($this->admin->can('view', $this->cred))->toBeTrue();
});

test('member cannot manage or read credentials', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);
    expect($this->member->can('create', AiProviderCredential::class))->toBeFalse()
        ->and($this->member->can('view', $this->cred))->toBeFalse();
});

test('admin of another team cannot touch this team credential', function () {
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherAdmin->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($otherAdmin);
    session(['currentTeam' => ['id' => $otherTeam->id]]);
    expect($otherAdmin->can('update', $this->cred))->toBeFalse();
});
