<?php

use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user);
    session(['currentTeam' => $team]);
});

test('converts scp-style ssh urls with custom usernames to https', function () {
    Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('repository_url', 'custom-user@git.example.com:organization/repository.git')
        ->call('loadBranch')
        ->assertSet('repository_url', 'https://git.example.com/organization/repository.git')
        ->assertSet('branchFound', true);
});

test('strips custom ports when converting scp-style ssh urls to https', function () {
    Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('repository_url', 'custom-user@git.example.com:2222/organization/repository.git')
        ->call('loadBranch')
        ->assertSet('repository_url', 'https://git.example.com/organization/repository.git')
        ->assertSet('branchFound', true);
});
